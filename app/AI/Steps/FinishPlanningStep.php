<?php

namespace App\AI\Steps;

use App\Enums\StepStatus;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\WorkflowStep;
use App\Tasks\TaskService;
use App\Workflow\Contracts\StepHandler;
use App\Workflow\Exceptions\NonRetryableStepException;
use App\Workflow\StepContext;
use App\Workflow\StepResult;

/**
 * Last step of an orchestrated plan. Agents do not change code yet, so the
 * task is not left in Planning (which claims work is happening, G6): it
 * returns to Ready with its plan, or is blocked when a specialist needs
 * clarification from the owner. The quality gates (Phase 7) check whatever
 * is in the task workspace.
 */
class FinishPlanningStep implements StepHandler
{
    public const NAME = 'task.planning_finished';

    public function __construct(private readonly TaskService $tasks) {}

    public function handle(WorkflowStep $step, StepContext $context): StepResult
    {
        $workflow = $step->workflow()->with('subject')->firstOrFail();
        $task = $workflow->subject;

        if (! $task instanceof Task) {
            throw new NonRetryableStepException('The planning workflow has no task.');
        }

        $results = $workflow->steps()
            ->where('name', RunSpecialistStep::NAME)
            ->where('status', StepStatus::Completed)
            ->orderBy('position')
            ->get()
            ->map(fn (WorkflowStep $done) => $done->output['result'] ?? [])
            ->filter();

        $needInput = $results->filter(fn (array $result) => ($result['status'] ?? null) === 'blocked')->pluck('agent')->values();

        if ($task->refresh()->status === TaskStatus::Planning) {
            $needInput->isNotEmpty()
                ? $this->tasks->transition($task, TaskStatus::Blocked, 'system', null, 'AI plan needs your input: '.$needInput->join(', ').' could not plan without clarification. See the AI plan on the task.', TaskStatus::Planning)
                : $this->tasks->transition($task, TaskStatus::Ready, 'system', null, "AI plan ready from {$results->count()} specialists. Agents do not change code yet; quality checks run on the task workspace.", TaskStatus::Planning);
        }

        return new StepResult(output: ['specialists' => $results->count(), 'needs_input' => $needInput->all()]);
    }
}
