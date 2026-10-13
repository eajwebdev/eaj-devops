<?php

namespace App\Delivery\Steps;

use App\AI\AgentBrief;
use App\AI\Contracts\AgentRunnerInterface;
use App\AI\Exceptions\SpecialistRunFailedException;
use App\AI\PromptCatalog;
use App\Delivery\DeliveryContext;
use App\Delivery\DeliveryWorkflow;
use App\Delivery\EditApplier;
use App\Delivery\WorkspaceCalls;
use App\Enums\AgentRunStatus;
use App\Enums\AgentStatus;
use App\Enums\TaskPriority;
use App\Models\AgentRun;
use App\Models\Review;
use App\Models\Task;
use App\Models\WorkflowStep;
use App\Reliability\EventRecorder;
use App\Workflow\Contracts\StepHandler;
use App\Workflow\Exceptions\NonRetryableStepException;
use App\Workflow\StepContext;
use App\Workflow\StepResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Workflow step "agent.review": QA, senior or security review of the task's
 * diff (§36, §53, §74.1). The reviewer reports findings; the Studio decides
 * the verdict from them (§98, §99) — a reviewer saying "looks good" with a
 * MAJOR issue does not pass.
 */
class ReviewStep implements StepHandler
{
    public const NAME = 'agent.review';

    /** What blocks approval per gate (§98: Blocker/Major; §99: Critical/High). */
    private const BLOCKING = [
        'qa' => ['BLOCKER', 'MAJOR'],
        'senior' => ['BLOCKER', 'MAJOR'],
        'security' => ['CRITICAL', 'HIGH'],
    ];

    /** Reviewers sometimes use the other scale; translate rather than drop. */
    private const TO_CODE = ['CRITICAL' => 'BLOCKER', 'HIGH' => 'MAJOR', 'MEDIUM' => 'MINOR', 'LOW' => 'SUGGESTION', 'INFORMATIONAL' => 'SUGGESTION'];

    private const TO_SECURITY = ['BLOCKER' => 'CRITICAL', 'MAJOR' => 'HIGH', 'MINOR' => 'MEDIUM', 'SUGGESTION' => 'LOW'];

    public function __construct(
        private readonly AgentRunnerInterface $runner,
        private readonly WorkspaceCalls $workspace,
        private readonly DeliveryContext $contexts,
        private readonly EditApplier $paths,
        private readonly EventRecorder $events,
    ) {}

    public function handle(WorkflowStep $step, StepContext $context): StepResult
    {
        $workflow = $step->workflow()->with('subject.workspace.runner', 'subject.project')->firstOrFail();
        $task = $workflow->subject;
        $gate = (string) ($step->input['gate'] ?? '');
        $role = PromptCatalog::REVIEWERS[$gate] ?? null;
        $agent = $task instanceof Task && $role ? DeliveryWorkflow::agentFor($task->organization_id, $role) : null;

        if (! $task instanceof Task || ! $task->workspace || ! $agent) {
            throw new NonRetryableStepException("No reviewer or task workspace is available for the {$gate} review.");
        }

        if ($done = Review::where('workflow_step_id', $step->id)->first()) {
            return $this->result($done);
        }

        if ($context->shouldStop()) {
            throw new RuntimeException('AI operations are stopped; the step resumes from here.');
        }

        $runs = AgentRun::where('workflow_step_id', $step->id)->orderBy('id')->get(['id', 'status']);
        $ended = $runs->last() && in_array($runs->last()->status, [AgentRunStatus::Failed, AgentRunStatus::Blocked, AgentRunStatus::Cancelled], true);
        $key = "workflow-step:{$step->id}:run:".max(1, $runs->count() + ($ended ? 1 : 0));

        $diff = $this->workspace->diff($task->workspace, $context, "{$key}:diff");
        $diffFiles = collect($diff['files'] ?? [])->pluck('path')->all();

        if ($diffFiles === []) {
            throw new NonRetryableStepException('There is no change in the workspace to review.');
        }

        $brief = new AgentBrief(
            purpose: 'review',
            promptKey: PromptCatalog::key($role, 'review'),
            context: $this->contexts->review($task, $workflow, $step, $gate, $diff),
            activity: AgentStatus::Reviewing,
            message: 'Reviewing the diff of '.count($diffFiles)." file(s) on {$task->key()} ({$gate})",
            guard: fn (array $answer) => $this->verdict($answer, $gate, $task, $diffFiles),
            maxOutputTokens: (int) config('ai.max_output_tokens_review', 6000),
        );

        try {
            $result = $this->runner->runBrief($task, $agent, $brief, $key, $step->id, $context->heartbeat(...));
        } catch (SpecialistRunFailedException $e) {
            throw $e->retryable ? new RuntimeException($e->getMessage(), 0, $e) : new NonRetryableStepException($e->getMessage(), 0, $e);
        }

        $answer = (array) ($result['result'] ?? []);

        $review = DB::transaction(function () use ($task, $workflow, $step, $gate, $answer, $result, $diff) {
            $review = Review::forceCreate([
                'organization_id' => $task->organization_id,
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'workflow_id' => $workflow->id,
                'workflow_step_id' => $step->id,
                'agent_run_id' => AgentRun::where('public_id', $result['agent_run_id'] ?? '')->value('id'),
                'gate' => $gate,
                'cycle' => (int) ($step->input['cycle'] ?? 0),
                'head_sha' => $diff['head_sha'] ?? null,
                'passed' => (bool) $answer['passed'],
                'score' => (int) $answer['score'],
                'minimum_score' => (int) $answer['minimum_score'],
                'summary' => Str::limit((string) ($answer['summary'] ?? ''), 4000),
                'confidence' => $answer['confidence'] ?? null,
                'issues' => $answer['issues'],
                'acceptance' => $answer['acceptance_criteria'],
                'blocking' => $answer['blocking'],
                'warnings' => $answer['warnings'],
            ]);

            $this->events->record('delivery.review.completed', [
                'task_id' => $task->id,
                'task' => ['key' => $task->key(), 'title' => $task->title],
                'workflow_id' => $workflow->id,
                'review' => ['id' => $review->id, 'gate' => $gate, 'passed' => $review->passed, 'score' => $review->score, 'minimum_score' => $review->minimum_score, 'blocking' => count($review->blocking ?? [])],
            ], $task->project);

            return $review;
        });

        return $this->result($review);
    }

    /**
     * The deterministic verdict (§36, §96, §98, §99).
     *
     * @param  array<string, mixed>  $answer
     * @param  list<string>  $diffFiles
     * @return array<string, mixed>
     */
    public function verdict(array $answer, string $gate, Task $task, array $diffFiles): array
    {
        $warnings = array_values(array_filter((array) ($answer['warnings'] ?? []), 'is_string'));
        $security = $gate === 'security';

        $issues = collect((array) ($answer['issues'] ?? []))->filter(fn ($item) => is_array($item))->map(function (array $issue) use ($security, $diffFiles, &$warnings) {
            $severity = strtoupper((string) ($issue['severity'] ?? ''));
            $severity = $security ? (self::TO_SECURITY[$severity] ?? $severity) : (self::TO_CODE[$severity] ?? $severity);
            $file = is_string($issue['file'] ?? null) ? $this->paths->normalize($issue['file']) : null;

            // §48.8: a location the diff does not contain is not evidence.
            if ($file !== null && ! in_array($file, $diffFiles, true)) {
                $warnings[] = "Removed a reference to {$file}, which the diff does not change.";
                $file = null;
            }

            return [
                'severity' => $severity,
                'file' => $file,
                'line' => $file !== null && is_int($issue['line'] ?? null) ? $issue['line'] : null,
                'issue' => Str::limit((string) ($issue['issue'] ?? ''), 2000),
                'recommendation' => Str::limit((string) ($issue['recommendation'] ?? ''), 2000),
            ];
        })->values();

        $acceptance = collect((array) ($answer['acceptance_criteria'] ?? []))->filter(fn ($item) => is_array($item))->map(fn (array $c) => [
            'criterion' => Str::limit((string) ($c['criterion'] ?? ''), 1000),
            'met' => (bool) ($c['met'] ?? false),
            'evidence' => Str::limit((string) ($c['evidence'] ?? ''), 2000),
        ])->values();

        $blocking = $issues->filter(fn (array $i) => in_array($i['severity'], self::BLOCKING[$gate] ?? [], true))
            ->map(fn (array $i) => "{$i['severity']}: {$i['issue']}".($i['file'] ? " ({$i['file']}".($i['line'] ? ":{$i['line']}" : '').')' : ''))
            ->values()->all();

        if ($gate === 'qa') {
            foreach ($acceptance->where('met', false) as $criterion) {
                $blocking[] = "Acceptance criterion not met: {$criterion['criterion']}";
            }
            if (($task->acceptance_criteria ?? []) !== [] && $acceptance->isEmpty()) {
                $blocking[] = 'QA did not check the acceptance criteria.';
            }
        }

        $confidence = in_array($answer['confidence'] ?? null, ['HIGH', 'MEDIUM', 'LOW'], true) ? $answer['confidence'] : null;
        if ($confidence === 'LOW' && in_array($task->priority, [TaskPriority::Critical, TaskPriority::High], true)) {
            $blocking[] = "The reviewer's confidence is LOW on a {$task->priority->value} task (§96): a person should review it.";
        }

        $score = max(0, min(100, (int) ($answer['score'] ?? 0)));
        $minimum = (int) (config('eaj.review.minimum_score')[$gate] ?? 90);

        return [
            ...$answer,
            'task_id' => $task->key(),
            'agent' => PromptCatalog::REVIEWERS[$gate] ?? $gate,
            'gate' => $gate,
            'score' => $score,
            'minimum_score' => $minimum,
            'confidence' => $confidence,
            'issues' => $issues->all(),
            'acceptance_criteria' => $acceptance->all(),
            'blocking' => $blocking,
            'passed' => $score >= $minimum && $blocking === [],
            'warnings' => $warnings,
        ];
    }

    private function result(Review $review): StepResult
    {
        return new StepResult(output: [
            'review_id' => $review->id,
            'gate' => $review->gate,
            'passed' => $review->passed,
            'score' => $review->score,
            'minimum_score' => $review->minimum_score,
            'blocking' => count($review->blocking ?? []),
        ]);
    }
}
