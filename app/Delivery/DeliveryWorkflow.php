<?php

namespace App\Delivery;

use App\Agents\ModelRouter;
use App\AI\PromptCatalog;
use App\Delivery\Steps\AfterChecksStep;
use App\Delivery\Steps\ImplementStep;
use App\Delivery\Steps\ReviewDecisionStep;
use App\Delivery\Steps\ReviewStep;
use App\Delivery\Steps\TaskTransitionStep;
use App\Enums\TaskStatus;
use App\Enums\ToolKind;
use App\Enums\WorkflowStatus;
use App\Enums\WorkspaceStatus;
use App\Models\Agent;
use App\Models\ChangeSet;
use App\Models\Project;
use App\Models\ProjectTool;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Reliability\AuditLogger;
use App\Runner\Exceptions\RunnerUnavailableException;
use App\Tools\QualityChecks;
use App\Tools\Steps\CheckpointWorkspaceStep;
use App\Tools\Steps\QualityReportStep;
use App\Tools\Steps\RunToolStep;
use App\Workflow\WorkflowEngine;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AI delivery of a task (§33, §48.8, §69–70, §87): developers change the code
 * in the task workspace, the project's own tools check it, QA, senior and
 * security reviewers judge the diff, and the task reaches Release Ready —
 * or loops back through bounded fix and review cycles, or stops and asks.
 * Every stage is a durable workflow step; the task status follows the steps.
 */
class DeliveryWorkflow
{
    public const WORKFLOW = 'task.delivery';

    /** Order developers work in when several are needed: data → server → client → infrastructure. */
    private const ORDER = ['DB', 'BE', 'FE', 'DO'];

    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly ModelRouter $models,
        private readonly AuditLogger $audit,
    ) {}

    public function start(Task $task, User $user): Workflow
    {
        $task->loadMissing('project.organization', 'project.connection', 'workspace.runner');

        if (! in_array($task->status, [TaskStatus::Ready, TaskStatus::ChangesRequested], true)) {
            throw new DomainException("{$task->key()} must be Ready (or have changes requested) to start AI delivery.");
        }

        $workspace = $task->workspace;
        if (! $workspace || $workspace->status !== WorkspaceStatus::Ready) {
            throw new RunnerUnavailableException("Prepare the workspace for {$task->key()} first — agents only ever change code there.");
        }
        if (! $workspace->runner || $workspace->runner->revoked_at || ! $workspace->runner->isOnline()) {
            throw new RunnerUnavailableException("The runner for {$task->key()} is offline. Start it on that PC (eaj-runner start) and try again.");
        }
        $missing = array_diff(['tool.run', 'workspace.read', 'workspace.apply', 'workspace.diff'], $workspace->runner->capabilities ?? []);
        if ($missing !== []) {
            throw new RunnerUnavailableException("Runner \"{$workspace->runner->name}\" (v{$workspace->runner->version}) cannot change code yet. Update it to 0.3 or later and restart it.");
        }

        $developers = $this->developers($task);
        $reviewers = $this->reviewers($task);

        $mode = $task->status === TaskStatus::ChangesRequested ? 'changes' : 'implement';
        $steps = [
            self::transition(TaskStatus::InProgress, ($mode === 'changes' ? 'Implementing the requested changes' : 'AI delivery started').": {$developers->join(', ')}."),
            ...$this->tools($task->project)->where('kind', ToolKind::Install)->map(fn (ProjectTool $t) => ['name' => RunToolStep::NAME, 'input' => ['tool' => $t->key, 'mode' => 'check']])->values()->all(),
            ...self::implementRound($developers->all(), $mode, 0),
            ...self::checksRound($task->project, $developers->all(), $reviewers->all()),
        ];

        return DB::transaction(function () use ($task, $user, $steps, $developers, $reviewers, $mode) {
            // One delivery (and no separate quality check) per task at a time.
            Workflow::where('subject_type', $task->getMorphClass())
                ->where('subject_id', $task->id)
                ->whereIn('type', [self::WORKFLOW, QualityChecks::WORKFLOW])
                ->whereIn('status', [WorkflowStatus::Pending, WorkflowStatus::Running, WorkflowStatus::Paused, WorkflowStatus::Blocked])
                ->get()
                ->each(fn (Workflow $old) => $this->engine->cancel($old, 'Superseded by a new AI delivery'));

            $workflow = $this->engine->start($task, self::WORKFLOW, $steps, $task->project->organization, $task->project);

            $this->audit->log('delivery.started', $user, $task->project->organization, $task->project, $task, [
                'workflow_id' => $workflow->id,
                'developers' => $developers->all(),
                'reviewers' => $reviewers->all(),
                'mode' => $mode,
            ]);

            return $workflow;
        });
    }

    public function latest(Task $task): ?Workflow
    {
        return Workflow::where('subject_type', $task->getMorphClass())
            ->where('subject_id', $task->id)
            ->where('type', self::WORKFLOW)
            ->latest('id')
            ->first();
    }

    /** @return array{name: string, input: array<string, mixed>} */
    public static function transition(TaskStatus $to, string $reason): array
    {
        return ['name' => TaskTransitionStep::NAME, 'input' => ['to' => $to->value, 'reason' => $reason]];
    }

    /**
     * @param  list<string>  $roles
     * @return list<array{name: string, input: array<string, mixed>}>
     */
    public static function implementRound(array $roles, string $mode, int $cycle): array
    {
        return array_map(fn (string $role) => ['name' => ImplementStep::NAME, 'input' => ['role' => $role, 'mode' => $mode, 'cycle' => $cycle, 'peers' => $roles]], $roles);
    }

    /**
     * Phase 7 gates on the new code: checkpoint → formatters (fix) → checkpoint → static → tests → build → e2e → report.
     * The team travels in the decision step's input, so appended rounds know who works next.
     *
     * @param  list<string>  $developers  roles
     * @param  list<string>  $reviewers  gates
     * @return list<array{name: string, input: array<string, mixed>}>
     */
    public static function checksRound(Project $project, array $developers, array $reviewers): array
    {
        $tools = self::runnableTools($project);
        $formatters = $tools->where('kind', ToolKind::Format);

        return [
            self::transition(TaskStatus::Testing, 'Running the quality gates on the new code.'),
            ['name' => CheckpointWorkspaceStep::NAME, 'input' => ['reason' => 'agent changes']],
            ...$formatters->map(fn (ProjectTool $t) => ['name' => RunToolStep::NAME, 'input' => ['tool' => $t->key, 'mode' => $t->supports_fix ? 'fix' : 'check']])->values()->all(),
            ...($formatters->contains('supports_fix', true) ? [['name' => CheckpointWorkspaceStep::NAME, 'input' => ['reason' => 'formatter changes']]] : []),
            ...$tools->whereNotIn('kind', [ToolKind::Install, ToolKind::Format])->map(fn (ProjectTool $t) => ['name' => RunToolStep::NAME, 'input' => ['tool' => $t->key, 'mode' => 'check']])->values()->all(),
            ['name' => QualityReportStep::NAME, 'input' => []],
            ['name' => AfterChecksStep::NAME, 'input' => ['developers' => $developers, 'reviewers' => $reviewers]],
        ];
    }

    /**
     * §33: QA (while Testing) → Senior Review → Security Review → decision.
     *
     * @param  list<string>  $reviewers  gates
     * @param  list<string>  $developers  roles, for a changes round
     * @return list<array{name: string, input: array<string, mixed>}>
     */
    public static function reviewRound(array $reviewers, array $developers, int $cycle): array
    {
        $steps = [];
        foreach (PromptCatalog::REVIEWERS as $gate => $role) {
            if (! in_array($gate, $reviewers, true)) {
                continue;
            }
            if ($gate === 'senior') {
                $steps[] = self::transition(TaskStatus::Review, 'Quality gates passed; senior review.');
            }
            if ($gate === 'security') {
                $steps[] = self::transition(TaskStatus::SecurityReview, 'Security review of the diff (§74.1).');
            }
            $steps[] = ['name' => ReviewStep::NAME, 'input' => ['gate' => $gate, 'role' => $role, 'cycle' => $cycle]];
        }
        $steps[] = ['name' => ReviewDecisionStep::NAME, 'input' => ['cycle' => $cycle, 'developers' => $developers, 'reviewers' => $reviewers]];

        return $steps;
    }

    /** @return Collection<int, ProjectTool> */
    public static function runnableTools(Project $project): Collection
    {
        return $project->tools()->get()->filter->runnable()->sortBy(fn (ProjectTool $t) => $t->kind->order())->values();
    }

    /** @return Collection<int, ProjectTool> */
    private function tools(Project $project): Collection
    {
        $tools = self::runnableTools($project);

        if ($tools->reject(fn (ProjectTool $t) => $t->kind === ToolKind::Install)->isEmpty()) {
            throw new DomainException('No quality tools are enabled for this project, so nothing could check the agents\' work. Re-scan it, or switch tools on under Engineering tools.');
        }

        return $tools;
    }

    /**
     * Developers the impact analysis asked for, in working order; otherwise the
     * backend developer (or frontend for a frontend-only project).
     *
     * @return Collection<int, string>
     */
    private function developers(Task $task): Collection
    {
        $recommended = collect($task->impact['agents'] ?? [])->pluck('role')->filter(fn ($r) => in_array($r, PromptCatalog::IMPLEMENTERS, true))->unique();

        if ($recommended->isEmpty()) {
            $stack = $task->project->connection?->detected_stack ?? [];
            $recommended = collect([empty($stack['backend']) && ! empty($stack['frontend']) ? 'FE' : 'BE']);
        }

        $roles = $recommended->sortBy(fn ($r) => array_search($r, self::ORDER, true))->values();
        $this->assertAvailable($task, $roles, 'implementation');

        return $roles;
    }

    /**
     * QA, senior and security review are all part of the Definition of Done (§87).
     *
     * @return Collection<int, string> gates
     */
    private function reviewers(Task $task): Collection
    {
        $this->assertAvailable($task, collect(array_values(PromptCatalog::REVIEWERS)), 'review');

        return collect(array_keys(PromptCatalog::REVIEWERS));
    }

    /** @param Collection<int, string> $roles */
    private function assertAvailable(Task $task, Collection $roles, string $purpose): void
    {
        $agents = Agent::where('organization_id', $task->organization_id)->with('role')->get()->keyBy(fn (Agent $a) => $a->role->key);

        $problems = $roles->map(function (string $role) use ($agents, $purpose) {
            $agent = $agents->get($role);

            return match (true) {
                ! $agent => "no {$role} agent exists",
                ! $agent->enabled => "{$agent->name} is disabled",
                ! $this->models->resolve($agent, $purpose)['primary'] => "no AI model is routed to {$agent->name}",
                default => null,
            };
        })->filter();

        if ($problems->isNotEmpty()) {
            throw new DomainException('AI delivery cannot start: '.$problems->join('; ').'. Fix it under AI Team → Models and routes.');
        }
    }

    /**
     * §48.10 "route back to the responsible specialist": the developers whose
     * changes touched the files the failures point at; otherwise whoever changed
     * code in this delivery; otherwise everyone.
     *
     * @param  list<string>  $developers
     * @param  iterable<string|null>  $files
     * @return list<string>
     */
    public static function responsible(Workflow $workflow, array $developers, iterable $files): array
    {
        $files = collect($files)->filter()->unique();
        $touched = ChangeSet::where('workflow_id', $workflow->id)->where('status', 'applied')->get()
            ->groupBy('role')
            ->map(fn ($sets) => $sets->flatMap(fn (ChangeSet $c) => collect($c->files ?? [])->pluck('path'))->unique());

        $owners = array_values(array_filter($developers, fn ($role) => $touched->has($role) && $touched[$role]->intersect($files)->isNotEmpty()));
        $changedCode = array_values(array_filter($developers, fn ($role) => $touched->has($role)));

        return $owners ?: ($changedCode ?: $developers);
    }

    public static function agentFor(int $organizationId, string $role): ?Agent
    {
        return Agent::where('organization_id', $organizationId)->whereHas('role', fn ($q) => $q->where('key', $role))->with('role')->first();
    }
}
