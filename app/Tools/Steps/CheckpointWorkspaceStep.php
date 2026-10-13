<?php

namespace App\Tools\Steps;

use App\Enums\RunnerCommandStatus;
use App\Enums\RunnerCommandType;
use App\Enums\WorkspaceStatus;
use App\Models\Task;
use App\Models\WorkflowStep;
use App\Runner\RunnerGateway;
use App\Tools\RunnerWaiter;
use App\Workflow\Contracts\StepHandler;
use App\Workflow\Exceptions\NonRetryableStepException;
use App\Workflow\StepContext;
use App\Workflow\StepResult;
use RuntimeException;

/**
 * Workflow step "workspace.checkpoint": commit the workspace as it is now
 * (Plan §48.4), so what the next tools check is a SHA, not a moving target.
 * The engine records that SHA on the step's checkpoint (G3).
 */
class CheckpointWorkspaceStep implements StepHandler
{
    public const NAME = 'workspace.checkpoint';

    public function __construct(
        private readonly RunnerGateway $gateway,
        private readonly RunnerWaiter $waiter,
    ) {}

    public function handle(WorkflowStep $step, StepContext $context): StepResult
    {
        $workflow = $step->workflow()->with('subject.workspace.runner', 'subject.project')->firstOrFail();
        $task = $workflow->subject;
        $workspace = $task instanceof Task ? $task->workspace : null;

        if (! $workspace || $workspace->status !== WorkspaceStatus::Ready || ! $workspace->runner) {
            throw new NonRetryableStepException('The task workspace is not ready, so it cannot be checkpointed.');
        }

        $command = $this->gateway->dispatch($workspace->runner, RunnerCommandType::WorkspaceCheckpoint, [
            'workspace_id' => $workspace->id,
            'workspace_path' => $workspace->path,
            'task_key' => $workspace->task_key,
            'step' => $step->id,
        ], $task->project, idempotencyKey: "workflow-step:{$step->id}:attempt:{$step->attempt}");

        $command = $this->waiter->command($command, $context, 10 * 60);

        if ($command->status !== RunnerCommandStatus::Succeeded) {
            throw new RuntimeException('Checkpoint failed: '.($command->error_message ?? $command->status->value));
        }

        $sha = (string) ($command->result['sha'] ?? '');

        return new StepResult(
            output: ['sha' => $sha, 'changed' => (bool) ($command->result['changed'] ?? false), 'files' => count((array) ($command->result['files'] ?? [])), 'reason' => $step->input['reason'] ?? null],
            commitSha: $sha ?: null,
        );
    }
}
