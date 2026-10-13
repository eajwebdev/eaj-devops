<?php

namespace App\AI;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\Workflow;
use App\Tasks\TaskService;
use App\Workflow\Contracts\WorkflowListener;
use Illuminate\Support\Str;

/** The task shows Planning only while its planning workflow is actually running (G6). */
class PlanningWorkflowListener implements WorkflowListener
{
    public function __construct(private readonly TaskService $tasks) {}

    public function blocked(Workflow $workflow, string $detail): void
    {
        $task = $this->task($workflow);
        if ($task?->status === TaskStatus::Planning) {
            $this->tasks->transition($task, TaskStatus::Blocked, 'system', null, 'AI planning stopped: '.Str::limit($detail, 400), TaskStatus::Planning);
        }
    }

    public function cancelled(Workflow $workflow, string $reason): void
    {
        $task = $this->task($workflow);
        if ($task?->status === TaskStatus::Planning) {
            $this->tasks->transition($task, TaskStatus::Ready, 'system', null, "AI planning cancelled: {$reason}", TaskStatus::Planning);
        }
    }

    public function resumed(Workflow $workflow): void
    {
        $task = $this->task($workflow);
        if ($task?->status === TaskStatus::Blocked && $task->blocked_from === TaskStatus::Planning) {
            $this->tasks->transition($task, TaskStatus::Planning, 'system', null, 'AI planning resumed.', TaskStatus::Blocked);
        }
    }

    private function task(Workflow $workflow): ?Task
    {
        $task = $workflow->subject()->first();

        return $task instanceof Task ? $task : null;
    }
}
