<?php

namespace App\Workflow\Contracts;

use App\Models\WorkflowStep;
use App\Workflow\StepContext;
use App\Workflow\StepResult;

/**
 * Executes one workflow step. Register implementations in config/eaj.php
 * under `step_handlers`, keyed by step name.
 *
 * Handlers must be safe to re-run from the last checkpoint: the engine may
 * call them again after a crash, a stall or a retry. Throw
 * NonRetryableStepException for failures a retry cannot fix.
 */
interface StepHandler
{
    public function handle(WorkflowStep $step, StepContext $context): StepResult;
}
