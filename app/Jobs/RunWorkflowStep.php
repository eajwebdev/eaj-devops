<?php

namespace App\Jobs;

use App\Workflow\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Delivers "run step N" (Plan §48.2). All retry logic lives in the engine,
 * driven by persisted state, so the queue itself never retries.
 */
class RunWorkflowStep implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly int $stepId)
    {
        $this->timeout = (int) config('eaj.limits.max_step_seconds');
        // A connection whose retry_after outlives the step, so a long AI call is never redelivered mid-flight.
        $this->onConnection(config('eaj.agent_queue_connection'));
        $this->onQueue('agents');
    }

    public function handle(WorkflowEngine $engine): void
    {
        $engine->runStep($this->stepId);
    }

    /**
     * Timeouts and worker crashes end up here; the engine decides whether to retry.
     */
    public function failed(?Throwable $exception): void
    {
        app(WorkflowEngine::class)->failStep($this->stepId, 'Worker failure: '.($exception?->getMessage() ?? 'unknown error'));
    }
}
