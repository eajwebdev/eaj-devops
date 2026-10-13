<?php

namespace App\Delivery;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\Workflow;
use App\Tasks\TaskService;
use App\Tools\QualityChecksListener;
use App\Workflow\Contracts\WorkflowListener;
use Illuminate\Support\Str;

/**
 * The task shows agent work only while the delivery is really running (G6):
 * a blocked or cancelled delivery leaves the task Blocked with the reason, and
 * no tool keeps running on the PC; resuming puts it back where it stopped.
 */
class DeliveryListener implements WorkflowListener
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly QualityChecksListener $tools,
    ) {}

    public function blocked(Workflow $workflow, string $detail): void
    {
        $this->tools->blocked($workflow, $detail);
        $this->block($workflow, 'AI delivery stopped: '.Str::limit($detail, 400));
    }

    public function cancelled(Workflow $workflow, string $reason): void
    {
        $this->tools->cancelled($workflow, $reason);
        // Nothing runs any more; the owner unblocks it back to Ready (ADR-009).
        $this->block($workflow, "AI delivery cancelled: {$reason}");
    }

    public function resumed(Workflow $workflow): void
    {
        $task = $workflow->subject()->first();

        if ($task instanceof Task && $task->status === TaskStatus::Blocked && $task->blocked_from?->isAgentWork()) {
            $this->tasks->transition($task, $task->blocked_from, 'system', null, 'AI delivery resumed.', TaskStatus::Blocked);
        }
    }

    private function block(Workflow $workflow, string $reason): void
    {
        $task = $workflow->subject()->first();

        if ($task instanceof Task && $task->status->isAgentWork()) {
            $this->tasks->transition($task, TaskStatus::Blocked, 'system', null, $reason, $task->status);
        }
    }
}
