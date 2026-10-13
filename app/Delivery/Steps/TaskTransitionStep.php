<?php

namespace App\Delivery\Steps;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\WorkflowStep;
use App\Tasks\TaskException;
use App\Tasks\TaskService;
use App\Tasks\TaskStateMachine;
use App\Workflow\Contracts\StepHandler;
use App\Workflow\Exceptions\NonRetryableStepException;
use App\Workflow\StepContext;
use App\Workflow\StepResult;

/**
 * Workflow step "task.transition": the task status follows the work that is
 * really happening (G6). Idempotent — re-running a completed move is a no-op —
 * and it never overrides a person: if someone moved the task elsewhere, the
 * delivery stops and asks.
 */
class TaskTransitionStep implements StepHandler
{
    public const NAME = 'task.transition';

    public function __construct(private readonly TaskService $tasks) {}

    public function handle(WorkflowStep $step, StepContext $context): StepResult
    {
        $task = $step->workflow()->with('subject')->firstOrFail()->subject;
        $to = TaskStatus::tryFrom((string) ($step->input['to'] ?? ''));

        if (! $task instanceof Task || ! $to) {
            throw new NonRetryableStepException('This step has no task or no target status.');
        }

        $task->refresh();

        if ($task->status === $to) {
            return new StepResult(output: ['from' => $to->value, 'to' => $to->value, 'changed' => false]);
        }

        if (! TaskStateMachine::exists($task->status, $to)) {
            throw new NonRetryableStepException("{$task->key()} is now {$task->status->label()} (moved by a person), so the AI delivery cannot move it to {$to->label()}. Resume when it should continue, or cancel.");
        }

        $from = $task->status;

        try {
            $this->tasks->transition($task, $to, 'system', null, (string) ($step->input['reason'] ?? ''), $from);
        } catch (TaskException $e) {
            throw new NonRetryableStepException($e->getMessage(), 0, $e);
        }

        return new StepResult(output: ['from' => $from->value, 'to' => $to->value, 'changed' => true]);
    }
}
