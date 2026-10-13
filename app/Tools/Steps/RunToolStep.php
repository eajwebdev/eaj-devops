<?php

namespace App\Tools\Steps;

use App\Enums\StepStatus;
use App\Enums\ToolKind;
use App\Enums\ToolRunStatus;
use App\Models\Task;
use App\Models\ToolRun;
use App\Models\WorkflowStep;
use App\Tools\Exceptions\ToolUnavailableException;
use App\Tools\RunnerWaiter;
use App\Tools\ToolRunService;
use App\Workflow\Contracts\StepHandler;
use App\Workflow\Exceptions\NonRetryableStepException;
use App\Workflow\StepContext;
use App\Workflow\StepResult;
use RuntimeException;

/**
 * Workflow step "tool.run": one approved tool in the task workspace. A failing
 * check completes the step — the verdict is the quality report's job; only
 * "could not get a result" (runner gone, time limit) fails the step and retries.
 */
class RunToolStep implements StepHandler
{
    public const NAME = 'tool.run';

    /** Runner-side failures that say nothing about the code: try again. */
    private const TRANSIENT = ['LEASE_EXPIRED', 'EXPIRED'];

    public function __construct(
        private readonly ToolRunService $tools,
        private readonly RunnerWaiter $waiter,
    ) {}

    public function handle(WorkflowStep $step, StepContext $context): StepResult
    {
        $workflow = $step->workflow()->with('subject.workspace.runner')->firstOrFail();
        $task = $workflow->subject;
        $key = (string) ($step->input['tool'] ?? '');

        if (! $task instanceof Task || ! $task->workspace) {
            throw new NonRetryableStepException('This quality check has no task workspace.');
        }

        if ($reason = $this->dependencyFailed($step, $key)) {
            return new StepResult(output: ['tool' => $key, 'status' => ToolRunStatus::Skipped->value, 'reason' => $reason]);
        }

        try {
            $run = $this->tools->request(
                $task->workspace,
                $key,
                (string) ($step->input['mode'] ?? 'check'),
                step: $step,
                idempotencyKey: "workflow-step:{$step->id}:attempt:{$step->attempt}",
            );
        } catch (ToolUnavailableException $e) {
            // Switched off or gone since the checks started: report it, do not stop the other gates.
            return new StepResult(output: ['tool' => $key, 'status' => ToolRunStatus::Skipped->value, 'reason' => $e->getMessage()]);
        }

        $run = $this->waiter->toolRun($run, $context, max(60, $workflow->limit('max_step_seconds') - 60));

        if ($run->status === ToolRunStatus::Error && in_array($run->error_code, self::TRANSIENT, true)) {
            throw new RuntimeException("{$run->label}: {$run->error_message}");
        }

        if ($run->status === ToolRunStatus::Cancelled) {
            throw new RuntimeException("{$run->label} was stopped before it finished.");
        }

        return new StepResult(
            output: [
                'tool_run_id' => $run->id,
                'tool' => $run->tool_key,
                'kind' => $run->kind->value,
                'status' => $run->status->value,
                'summary' => $run->summary(),
                'head_sha' => $run->head_sha,
            ],
            state: ['tool_run_id' => $run->id],
        );
    }

    /**
     * Gates after a failed dependency install cannot say anything about the code.
     */
    private function dependencyFailed(WorkflowStep $step, string $key): ?string
    {
        $ecosystem = strtok($key, '.');

        $install = ToolRun::query()
            ->where('workflow_id', $step->workflow_id)
            ->where('kind', ToolKind::Install)
            ->where('tool_key', 'like', "{$ecosystem}.%")
            ->whereIn('workflow_step_id', $step->workflow->steps()->where('status', StepStatus::Completed)->select('id'))
            ->latest('id')
            ->first();

        return $install && ! $install->status->passesGate() && $install->tool_key !== $key
            ? "{$install->label} did not succeed, so this check could not run."
            : null;
    }
}
