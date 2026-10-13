<?php

namespace App\Delivery;

use App\AI\Orchestrator;
use App\AI\Steps\RunSpecialistStep;
use App\Delivery\Steps\ReviewStep;
use App\Enums\StepStatus;
use App\Enums\ToolRunStatus;
use App\Models\ChangeSet;
use App\Models\Review;
use App\Models\Task;
use App\Models\ToolRun;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Security\SecretRedactor;
use App\Tools\Steps\QualityReportStep;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What each delivery agent sees (§42, §53): only what its job needs, redacted
 * (§16), with tool results as facts rather than claims (§48.8).
 */
class DeliveryContext
{
    private const MAX_FILES = 40;

    private const IMPACT_FILES = 8;

    private const IMPACT_TESTS = 4;

    private const MAX_DIFF_CHARS = 120_000;

    public function __construct(
        private readonly SecretRedactor $redactor,
        private readonly EditApplier $paths,
    ) {}

    /**
     * Files a developer is shown: the impact analysis' files and tests, what the
     * task already changed, and — in fix and changes rounds — every file a
     * failure or review issue points at.
     *
     * @return list<string>
     */
    public function filesFor(Task $task, Workflow $workflow, string $mode): array
    {
        $impact = $task->impact ?? [];
        // The impact analysis ranks by relevance; its long tail (routes files, base controllers) mostly costs tokens.
        $paths = collect([
            ...array_slice((array) ($impact['expected_files'] ?? []), 0, self::IMPACT_FILES),
            ...array_slice((array) ($impact['expected_tests'] ?? []), 0, self::IMPACT_TESTS),
        ])->pluck('path');

        $paths = $paths->merge($this->changedFiles($task));

        if ($mode === 'fix') {
            $paths = $this->failingRuns($workflow, PHP_INT_MAX)->flatMap(fn (ToolRun $r) => [
                ...collect($r->failures ?? [])->pluck('file'),
                ...collect($r->issues ?? [])->pluck('file'),
                ...($r->files_changed ?? []),
            ])->merge($paths);
        }

        if ($mode === 'changes') {
            $paths = $this->latestReviews($task)->flatMap(fn (Review $r) => collect($r->issues ?? [])->pluck('file'))->merge($paths);
        }

        return $paths->filter(fn ($p) => is_string($p) && $this->paths->normalize($p) !== null)
            ->map(fn (string $p) => $this->paths->normalize($p))
            ->unique()
            ->take(self::MAX_FILES)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $files  runner read results, by path
     * @param  list<string>  $peers
     * @return array<string, mixed>
     */
    public function implementation(Task $task, Workflow $workflow, WorkflowStep $step, string $role, string $mode, array $files, array $peers): array
    {
        $task->loadMissing('project.connection', 'comments');

        return $this->redactor->redact([
            'task' => $this->task($task),
            'your_role' => $role,
            'team' => count($peers) > 1 ? 'Developers in this round, in order: '.implode(', ', $peers).'. Change only what your role owns; later developers see your changes.' : null,
            'mode' => $mode,
            'mode_help' => match ($mode) {
                'fix' => 'The quality gates failed on the current code. Fix exactly the failures in "feedback".',
                'changes' => 'Reviewers or the owner asked for changes. Address every issue in "feedback".',
                default => 'Implement the task.',
            },
            'stack' => Arr::only($task->project->connection?->detected_stack ?? [], ['backend', 'frontend', 'bridge', 'bundler', 'css', 'language', 'database', 'tests', 'designSystem', 'packageManager']),
            'plan' => $this->plan($task),
            'impact' => Arr::only($task->impact ?? [], ['areas', 'expected_files', 'expected_tests', 'notes']),
            'changes_so_far' => ChangeSet::where('task_id', $task->id)->where('workflow_id', $workflow->id)->orderBy('id')->get()
                ->map(fn (ChangeSet $c) => ['role' => $c->role, 'mode' => $c->mode, 'summary' => $c->summary, 'files' => collect($c->files ?? [])->pluck('path')->all()])->all(),
            'feedback' => match ($mode) {
                'fix' => $this->failures($workflow, $step->position),
                'changes' => $this->reviewFeedback($task),
                default => null,
            },
            'files' => collect($files)->map(fn (array $f) => array_filter([
                'path' => $f['path'],
                'status' => $f['status'],
                'redacted' => $f['redacted'] ?? null,
                'content' => $f['content'] ?? null,
            ], fn ($v) => $v !== null))->values()->all(),
            'files_note' => 'You may create files that are not listed. To change a listed file, use "edit" with search text copied exactly from its content.',
        ]);
    }

    /**
     * Diff-first review context (§53).
     *
     * @param  array<string, mixed>  $diff  runner workspace.diff result
     * @return array<string, mixed>
     */
    public function review(Task $task, Workflow $workflow, WorkflowStep $step, string $gate, array $diff): array
    {
        $task->loadMissing('project.connection', 'comments');
        $text = (string) ($diff['diff'] ?? '');
        $report = $this->latestReport($workflow, $step->position);

        return $this->redactor->redact([
            'task' => $this->task($task),
            'gate' => $gate,
            'stack' => Arr::only($task->project->connection?->detected_stack ?? [], ['backend', 'frontend', 'bridge', 'language', 'database', 'auth', 'payments']),
            'plan' => $this->plan($task),
            'changes' => ChangeSet::where('workflow_id', $workflow->id)->orderBy('id')->get()
                ->map(fn (ChangeSet $c) => ['role' => $c->role, 'mode' => $c->mode, 'summary' => $c->summary, 'confidence' => $c->confidence, 'out_of_scope' => $c->out_of_scope])->all(),
            'quality_gates' => $report ? ['passed' => $report['passed'] ?? false, 'gates' => collect($report['gates'] ?? [])->map(fn ($g) => Arr::only($g, ['label', 'status', 'runs']))->all()] : null,
            'diff_files' => $diff['files'] ?? [],
            'diff' => Str::limit($text, self::MAX_DIFF_CHARS, "\n[diff truncated]"),
            'previous_review' => ($previous = $this->latestReviews($task)->firstWhere('gate', $gate))
                ? ['score' => $previous->score, 'issues' => $previous->issues] : null,
            'owner_requests' => $this->ownerRequests($task),
        ]);
    }

    /** @return array<string, mixed> */
    private function task(Task $task): array
    {
        return [
            'id' => $task->key(),
            'title' => $task->title,
            'description' => $task->description,
            'acceptance_criteria' => $task->acceptance_criteria ?? [],
            'type' => $task->type->value,
            'priority' => $task->priority->value,
            'instructions' => $task->comments->where('kind', 'instruction')->take(-10)->pluck('body')->values()->all(),
        ];
    }

    /**
     * The specialists' summaries from the task's latest finished AI plan.
     *
     * @return list<array<string, mixed>>
     */
    private function plan(Task $task): array
    {
        $workflow = Workflow::where('subject_type', $task->getMorphClass())->where('subject_id', $task->id)
            ->where('type', Orchestrator::WORKFLOW)->latest('id')->first();

        return $workflow ? $workflow->steps()->where('name', RunSpecialistStep::NAME)->where('status', StepStatus::Completed)->orderBy('position')->get()
            ->map(fn (WorkflowStep $s) => ['role' => $s->input['role'] ?? null, 'summary' => $s->output['result']['summary'] ?? null, 'warnings' => $s->output['result']['warnings'] ?? []])
            ->all() : [];
    }

    /** @return list<string> */
    private function changedFiles(Task $task): array
    {
        return ChangeSet::where('task_id', $task->id)->latest('id')->limit(10)->get()
            ->flatMap(fn (ChangeSet $c) => collect($c->files ?? [])->pluck('path'))->unique()->values()->all();
    }

    /**
     * Failing tool runs of the latest quality round before $position.
     *
     * @return Collection<int, ToolRun>
     */
    private function failingRuns(Workflow $workflow, int $position): Collection
    {
        $report = $workflow->steps()->where('name', QualityReportStep::NAME)->where('position', '<', $position)->reorder('position', 'desc')->first();
        if (! $report) {
            return collect();
        }

        $previous = $workflow->steps()->where('name', QualityReportStep::NAME)->where('position', '<', $report->position)->max('position') ?? -1;

        return ToolRun::where('workflow_id', $workflow->id)
            ->whereIn('workflow_step_id', $workflow->steps()->whereBetween('position', [$previous + 1, $report->position])->select('id'))
            ->whereNotIn('status', [ToolRunStatus::Passed, ToolRunStatus::Skipped])
            ->get();
    }

    /** @return array<string, mixed>|null */
    private function latestReport(Workflow $workflow, int $position): ?array
    {
        return $workflow->steps()->where('name', QualityReportStep::NAME)->where('position', '<', $position)->where('status', StepStatus::Completed)->reorder('position', 'desc')->first()?->output;
    }

    /** @return array<string, mixed> */
    private function failures(Workflow $workflow, int $position): array
    {
        return [
            'gate_report' => collect($this->latestReport($workflow, $position)['gates'] ?? [])->map(fn ($g) => Arr::only($g, ['label', 'status']))->all(),
            'failing_tools' => $this->failingRuns($workflow, $position)->map(fn (ToolRun $r) => [
                'tool' => $r->label,
                'command' => $r->command,
                'status' => $r->status->value,
                'summary' => $r->summary(),
                'failures' => collect($r->failures ?? [])->take(15)->all(),
                'issues' => collect($r->issues ?? [])->take(40)->all(),
                'error' => $r->error_message,
                // Raw output only when nothing could be parsed: it is all there is to go on.
                'output_tail' => empty($r->failures) && empty($r->issues) ? Str::limit((string) $r->output_tail, 4000) : null,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function reviewFeedback(Task $task): array
    {
        return [
            'reviews' => $this->latestReviews($task)->map(fn (Review $r) => [
                'gate' => $r->gate, 'passed' => $r->passed, 'score' => $r->score, 'minimum_score' => $r->minimum_score,
                'blocking' => $r->blocking, 'issues' => $r->issues, 'acceptance' => $r->acceptance,
            ])->values()->all(),
            'owner_requests' => $this->ownerRequests($task),
        ];
    }

    /** @return Collection<int, Review> latest review per gate */
    private function latestReviews(Task $task): Collection
    {
        return Review::where('task_id', $task->id)->orderByDesc('id')->limit(10)->get()->unique('gate')->values();
    }

    /** @return list<string> */
    private function ownerRequests(Task $task): array
    {
        return $task->comments->where('kind', 'changes_requested')->take(-5)->pluck('body')->values()->all();
    }

    /** Steps whose result counts as the review round for a decision. */
    public static function reviewSteps(Workflow $workflow, int $before): Collection
    {
        $after = $workflow->steps()->where('name', 'delivery.after_checks')->where('position', '<', $before)->max('position') ?? -1;

        return $workflow->steps()->where('name', ReviewStep::NAME)->whereBetween('position', [$after + 1, $before - 1])->orderBy('position')->get();
    }
}
