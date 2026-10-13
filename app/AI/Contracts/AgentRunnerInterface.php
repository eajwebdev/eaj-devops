<?php

namespace App\AI\Contracts;

use App\Models\Agent;
use App\Models\Task;
use Closure;

interface AgentRunnerInterface
{
    /**
     * Run one specialist on a task. Safe to call again with the same idempotency key:
     * a completed run returns its stored result instead of calling the provider again.
     *
     * @param  Closure(): void|null  $heartbeat  extra liveness signal for the caller (e.g. the workflow step)
     * @return array<string, mixed>
     */
    public function run(Task $task, Agent $agent, string $purpose, string $idempotencyKey, ?int $workflowStepId = null, ?Closure $heartbeat = null): array;
}
