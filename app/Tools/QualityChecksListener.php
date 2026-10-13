<?php

namespace App\Tools;

use App\Enums\ToolRunStatus;
use App\Models\ToolRun;
use App\Models\Workflow;
use App\Workflow\Contracts\WorkflowListener;

/** A cancelled or blocked quality check leaves no tool running on the PC (§57, G6). */
class QualityChecksListener implements WorkflowListener
{
    public function __construct(private readonly ToolRunService $tools) {}

    public function blocked(Workflow $workflow, string $detail): void
    {
        $this->stopTools($workflow, 'The quality check stopped: '.$detail);
    }

    public function cancelled(Workflow $workflow, string $reason): void
    {
        $this->stopTools($workflow, $reason);
    }

    public function resumed(Workflow $workflow): void {}

    private function stopTools(Workflow $workflow, string $reason): void
    {
        ToolRun::where('workflow_id', $workflow->id)
            ->whereIn('status', [ToolRunStatus::Queued, ToolRunStatus::Running])
            ->get()
            ->each(fn (ToolRun $run) => $this->tools->cancel($run, reason: $reason));
    }
}
