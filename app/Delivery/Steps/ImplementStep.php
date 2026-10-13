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
use App\Enums\RunnerCommandStatus;
use App\Models\AgentRun;
use App\Models\ChangeSet;
use App\Models\RunnerCommand;
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
 * Workflow step "agent.implement": one developer changes the code (§48.8).
 *
 * read the files (redacted, with hashes) → the agent answers with edits →
 * the Studio turns them into exact writes (one correction round if they do
 * not apply) → the runner writes them all-or-nothing, only onto files that
 * are still exactly as read → the applied diff is recorded as a change set.
 * Nothing the agent says it did counts; the change set is what happened.
 */
class ImplementStep implements StepHandler
{
    public const NAME = 'agent.implement';

    public function __construct(
        private readonly AgentRunnerInterface $runner,
        private readonly WorkspaceCalls $workspace,
        private readonly EditApplier $edits,
        private readonly DeliveryContext $contexts,
        private readonly EventRecorder $events,
    ) {}

    public function handle(WorkflowStep $step, StepContext $context): StepResult
    {
        $workflow = $step->workflow()->with('subject.workspace.runner', 'subject.project')->firstOrFail();
        $task = $workflow->subject;
        $role = (string) ($step->input['role'] ?? '');
        $mode = (string) ($step->input['mode'] ?? 'implement');
        $agent = $task instanceof Task ? DeliveryWorkflow::agentFor($task->organization_id, $role) : null;

        if (! $task instanceof Task || ! $task->workspace || ! $agent) {
            throw new NonRetryableStepException("No {$role} developer or task workspace is available for this step.");
        }

        // Already applied (a crash between applying and completing): never apply twice (G4).
        if ($done = ChangeSet::where('workflow_step_id', $step->id)->first()) {
            return $this->result($done);
        }

        if ($context->shouldStop()) {
            throw new RuntimeException('AI operations are stopped; the step resumes from here.');
        }

        $key = $this->runKey($step);
        $workspace = $task->workspace;

        // Files are read fresh for every run: the write is refused if any changed since.
        $paths = $this->contexts->filesFor($task, $workflow, $mode);
        $read = $paths ? $this->workspace->read($workspace, $paths, $context, "{$key}:read") : ['head_sha' => null, 'files' => []];
        $files = collect($read['files'])->keyBy('path')->all();

        // Paths the agent wants to create or change that it was not shown: look them up once.
        $probe = function (array $changes) use (&$files, $workspace, $context, $key) {
            $unknown = collect($changes)->pluck('path')->map(fn ($p) => $this->edits->normalize((string) $p))->filter()->reject(fn ($p) => isset($files[$p]))->unique()->take(60)->values()->all();
            if ($unknown !== []) {
                foreach ($this->workspace->read($workspace, $unknown, $context, "{$key}:probe:".substr(sha1(implode("\n", $unknown)), 0, 12))['files'] as $file) {
                    $files[$file['path']] = $file;
                }
            }
        };

        $brief = new AgentBrief(
            purpose: 'implementation',
            promptKey: PromptCatalog::key($role, 'implement'),
            context: $this->contexts->implementation($task, $workflow, $step, $role, $mode, $files, (array) ($step->input['peers'] ?? [$role])),
            activity: $mode === 'implement' ? AgentStatus::Working : AgentStatus::Fixing,
            message: match ($mode) {
                'fix' => "Fixing the failing quality gates on {$task->key()}",
                'changes' => "Making the requested changes on {$task->key()}",
                default => "Implementing {$task->key()} from ".count(array_filter($files, fn ($f) => $f['status'] === 'ok')).' files',
            },
            check: function (array $answer) use (&$files, $probe): array {
                if (($answer['status'] ?? null) === 'blocked') {
                    return [];
                }
                $probe($answer['changes'] ?? []);

                return $this->edits->apply($files, $answer['changes'] ?? [])['problems'];
            },
            guard: function (array $answer) use (&$files, $task, $role): array {
                $writes = ($answer['status'] ?? null) === 'blocked' ? [] : $this->edits->apply($files, $answer['changes'] ?? [])['writes'];

                return [
                    ...$answer,
                    'task_id' => $task->key(),
                    'agent' => $role,
                    // The exact writes, stored with the run so a retry applies the same thing (§48.3).
                    'writes' => $writes,
                ];
            },
            maxOutputTokens: (int) config('ai.max_output_tokens_implementation', 16000),
        );

        try {
            $result = $this->runner->runBrief($task, $agent, $brief, $key, $step->id, $context->heartbeat(...));
        } catch (SpecialistRunFailedException $e) {
            throw $e->retryable ? new RuntimeException($e->getMessage(), 0, $e) : new NonRetryableStepException($e->getMessage(), 0, $e);
        }

        $answer = (array) ($result['result'] ?? []);

        if (($answer['status'] ?? null) === 'blocked') {
            $questions = collect($answer['questions'] ?? [])->filter()->join(' ');

            throw new NonRetryableStepException("{$agent->name} needs your input before changing code: ".($questions ?: ($answer['summary'] ?? 'see the agent run')).' Add an instruction on the task, then resume.');
        }

        $writes = (array) ($answer['writes'] ?? []);
        $applied = $writes === [] ? null : $this->workspace->apply($workspace, $writes, $context, "{$key}:apply");

        $changeSet = $this->record($task, $workflow->id, $step, $role, $mode, $result, $answer, $writes, $applied, $read['head_sha'] ?? null);

        return $this->result($changeSet);
    }

    /**
     * Same key = resume the step's run. A new run when the last one ended without a result,
     * or its writes were refused because the files changed meanwhile (read again, answer again).
     */
    private function runKey(WorkflowStep $step): string
    {
        $runs = AgentRun::where('workflow_step_id', $step->id)->orderBy('id')->get(['id', 'status', 'idempotency_key']);
        $last = $runs->last();
        $stale = $last && RunnerCommand::where('idempotency_key', "{$last->idempotency_key}:apply")
            ->whereIn('status', [RunnerCommandStatus::Failed, RunnerCommandStatus::Expired, RunnerCommandStatus::Cancelled])->exists();
        $ended = $last && in_array($last->status, [AgentRunStatus::Failed, AgentRunStatus::Blocked, AgentRunStatus::Cancelled], true);

        return "workflow-step:{$step->id}:run:".max(1, $runs->count() + ($ended || $stale ? 1 : 0));
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $answer
     * @param  list<array<string, mixed>>  $writes
     * @param  array<string, mixed>|null  $applied
     */
    private function record(Task $task, int $workflowId, WorkflowStep $step, string $role, string $mode, array $result, array $answer, array $writes, ?array $applied, ?string $head): ChangeSet
    {
        $actions = collect($writes)->mapWithKeys(fn ($w) => [$w['path'] => $w['action'] === 'delete' ? 'deleted' : ($w['base_sha1'] ? 'modified' : 'created')]);
        $files = collect($applied['files'] ?? [])->map(fn ($f) => [...$f, 'action' => $actions[$f['path']] ?? 'modified'])->values();
        $outOfScope = $this->outOfScope($task, $actions->keys()->all());

        return DB::transaction(function () use ($task, $workflowId, $step, $role, $mode, $result, $answer, $applied, $head, $files, $outOfScope) {
            $changeSet = ChangeSet::forceCreate([
                'organization_id' => $task->organization_id,
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'workflow_id' => $workflowId,
                'workflow_step_id' => $step->id,
                'agent_run_id' => AgentRun::where('public_id', $result['agent_run_id'] ?? '')->value('id'),
                'role' => $role,
                'mode' => $mode,
                'cycle' => (int) ($step->input['cycle'] ?? 0),
                'status' => $applied ? 'applied' : 'empty',
                'summary' => Str::limit((string) ($answer['summary'] ?? ''), 4000),
                'confidence' => in_array($answer['confidence'] ?? null, ['HIGH', 'MEDIUM', 'LOW'], true) ? $answer['confidence'] : null,
                'files' => $files->all(),
                'out_of_scope' => $outOfScope,
                'warnings' => array_values(array_filter((array) ($answer['warnings'] ?? []), 'is_string')),
                'additions' => (int) $files->sum('additions'),
                'deletions' => (int) $files->sum('deletions'),
                'diff' => $applied['diff'] ?? null,
                'base_head_sha' => $head,
            ]);

            $this->events->record('delivery.changes.applied', [
                'task_id' => $task->id,
                'task' => ['key' => $task->key(), 'title' => $task->title],
                'workflow_id' => $workflowId,
                'change_set' => $changeSet->toClientArray(),
            ], $task->project);

            return $changeSet;
        });
    }

    /**
     * §48.8: changes outside the task's declared scope need reviewer approval.
     * In scope: the impact analysis' files, new files beside them, tests, and files the task already changed.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function outOfScope(Task $task, array $paths): array
    {
        $declared = collect([...(array) ($task->impact['expected_files'] ?? []), ...(array) ($task->impact['expected_tests'] ?? [])])->pluck('path')->filter();

        if ($declared->isEmpty()) {
            return [];
        }

        $folders = $declared->map(fn ($p) => dirname($p))->unique();
        $earlier = ChangeSet::where('task_id', $task->id)->get()->flatMap(fn (ChangeSet $c) => collect($c->files ?? [])->pluck('path'));

        return collect($paths)->reject(fn ($p) => $declared->contains($p)
            || $earlier->contains($p)
            // Beside a declared file (not anywhere below its folder: "app/" would be the whole app).
            || $folders->contains(dirname($p))
            || preg_match('#(^|/)(tests?|__tests__|spec)/|\.(test|spec)\.[cm]?[jt]sx?$|Test\.php$#', $p))->values()->all();
    }

    private function result(ChangeSet $changeSet): StepResult
    {
        return new StepResult(output: [
            'change_set_id' => $changeSet->id,
            'role' => $changeSet->role,
            'status' => $changeSet->status,
            'files' => count($changeSet->files ?? []),
            'summary' => Str::limit((string) $changeSet->summary, 300),
        ]);
    }
}
