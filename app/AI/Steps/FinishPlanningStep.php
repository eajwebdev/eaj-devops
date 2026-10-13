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
 * Last step of an orchestrated plan. Implementation needs the engineering tools
 * of Phase 7, so the task is not left in Planning (which claims work is
 * happening, G6): it returns to Ready with its plan, or is blocked when a
 * specialist needs clarification from the owner.
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
                : $this->tasks->transition($task, TaskStatus::Ready, 'system', null, "AI plan ready from {$results->count()} specialists. Implementation needs the engineering tools (Phase 7).", TaskStatus::Planning);
        }

        return new StepResult(output: ['specialists' => $results->count(), 'needs_input' => $needInput->all()]);
    }
}
