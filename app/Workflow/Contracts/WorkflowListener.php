<?php

namespace App\Workflow\Contracts;

use App\Models\Workflow;

/**
 * Keeps a workflow's subject truthful when the workflow stops or restarts
 * (G6). Register per workflow type in config/eaj.php under `workflow_listeners`.
 * Called inside the engine's transaction, holding the workflow row lock.
 */
interface WorkflowListener
{
    public function blocked(Workflow $workflow, string $detail): void;

    public function cancelled(Workflow $workflow, string $reason): void;

    public function resumed(Workflow $workflow): void;
}
