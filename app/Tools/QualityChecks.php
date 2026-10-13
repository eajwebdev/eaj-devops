<?php

namespace App\Tools;

use App\Enums\ToolKind;
use App\Enums\WorkflowStatus;
use App\Enums\WorkspaceStatus;
use App\Models\ProjectTool;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Reliability\AuditLogger;
use App\Runner\Exceptions\RunnerUnavailableException;
use App\Tools\Exceptions\ToolUnavailableException;
use App\Tools\Steps\CheckpointWorkspaceStep;
use App\Tools\Steps\QualityReportStep;
use App\Tools\Steps\RunToolStep;
use App\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic quality gates on a task workspace (Plan §33, §48.8, §87):
 * dependencies → checkpoint → formatter (fix) → checkpoint → static analysis →
 * tests → build → end-to-end → report. Tools, not the AI, decide whether work
 * passes; each step is a durable workflow step, so a crash resumes where it
 * stopped and Stop AI pauses it.
 */
class QualityChecks
{
    public const WORKFLOW = 'task.quality_checks';

    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly AuditLogger $audit,
    ) {}

    public function start(Task $task, ?User $user): Workflow
    {
        $task->loadMissing('project.organization', 'workspace.runner');
        $workspace = $task->workspace;

        if (! $workspace || $workspace->status !== WorkspaceStatus::Ready) {
            throw new RunnerUnavailableException("Prepare the workspace for {$task->key()} before running quality checks.");
        }

        if (! $workspace->runner || $workspace->runner->revoked_at || ! $workspace->runner->isOnline()) {
            throw new RunnerUnavailableException("The runner for {$task->key()} is offline. Start it on that PC (eaj-runner start) and try again.");
        }

        if (! in_array('tool.run', $workspace->runner->capabilities ?? [], true)) {
            throw new RunnerUnavailableException("Runner \"{$workspace->runner->name}\" (v{$workspace->runner->version}) cannot run project tools yet. Update it to 0.2 or later and restart it.");
        }

        $tools = $task->project->tools()->get()->filter->runnable()->sortBy(fn (ProjectTool $t) => $t->kind->order())->values();

        if ($tools->reject(fn (ProjectTool $t) => $t->kind === ToolKind::Install)->isEmpty()) {
            throw new ToolUnavailableException('No quality tools are enabled for this project. Re-scan it, or switch tools on under Engineering tools.');
        }

        $steps = [];
        foreach ($tools->where('kind', ToolKind::Install) as $tool) {
            $steps[] = ['name' => RunToolStep::NAME, 'input' => ['tool' => $tool->key, 'mode' => 'check']];
        }

        // Results must belong to a commit (§48.9): uncommitted work is checkpointed first.
        $steps[] = ['name' => CheckpointWorkspaceStep::NAME, 'input' => ['reason' => 'before quality checks']];

        $formatters = $tools->where('kind', ToolKind::Format);
        foreach ($formatters as $tool) {
            $steps[] = ['name' => RunToolStep::NAME, 'input' => ['tool' => $tool->key, 'mode' => $tool->supports_fix ? 'fix' : 'check']];
        }
        if ($formatters->contains('supports_fix', true)) {
            $steps[] = ['name' => CheckpointWorkspaceStep::NAME, 'input' => ['reason' => 'formatter changes']];
        }

        foreach ($tools->whereNotIn('kind', [ToolKind::Install, ToolKind::Format]) as $tool) {
            $steps[] = ['name' => RunToolStep::NAME, 'input' => ['tool' => $tool->key, 'mode' => 'check']];
        }

        $steps[] = ['name' => QualityReportStep::NAME, 'input' => []];

        return DB::transaction(function () use ($task, $user, $steps, $tools) {
            Workflow::where('subject_type', $task->getMorphClass())
                ->where('subject_id', $task->id)
                ->where('type', self::WORKFLOW)
                ->whereIn('status', [WorkflowStatus::Pending, WorkflowStatus::Running, WorkflowStatus::Paused, WorkflowStatus::Blocked])
                ->get()
                ->each(fn (Workflow $old) => $this->engine->cancel($old, 'Superseded by a new quality check run'));

            $workflow = $this->engine->start($task, self::WORKFLOW, $steps, $task->project->organization, $task->project);

            $this->audit->log('quality.checks.started', $user, $task->project->organization, $task->project, $task, [
                'workflow_id' => $workflow->id,
                'tools' => $tools->pluck('key')->all(),
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
}
