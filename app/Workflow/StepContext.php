<?php

namespace App\Workflow;

use App\Models\WorkflowStep;

/**
 * Passed to a step handler so long-running work can report liveness and
 * stop cooperatively (between tool calls) when operations are halted.
 */
final class StepContext
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        public readonly WorkflowStep $step,
    ) {}

    public function heartbeat(): void
    {
        $this->engine->heartbeat($this->step->id);
    }

    public function shouldStop(): bool
    {
        return $this->engine->shouldStop($this->step->workflow_id);
    }
}
