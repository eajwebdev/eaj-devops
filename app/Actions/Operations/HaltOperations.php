<?php

namespace App\Actions\Operations;

use App\Agents\AgentRunService;
use App\Enums\AgentRunStatus;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Reliability\AuditLogger;
use App\Reliability\EventRecorder;
use App\Reliability\SystemHalt;
use App\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\DB;

/**
 * STOP ALL AI OPERATIONS (Plan §57).
 *
 * Stops new work, pauses every active workflow in scope at its current step,
 * and preserves workspaces, logs and task state. Running steps stop
 * cooperatively at their next StepContext::shouldStop() check; their result
 * is still checkpointed, but no further step is dispatched.
 *
 * A null organization halts the whole platform (Super Admin only).
 */
class HaltOperations
{
    public function __construct(
        private readonly SystemHalt $halt,
        private readonly WorkflowEngine $engine,
        private readonly EventRecorder $events,
        private readonly AuditLogger $audit,
        private readonly AgentRunService $runs,
    ) {}

    /**
     * @return list<int> IDs of the workflows that were paused
     */
    public function handle(User $user, ?Organization $organization, string $reason): array
    {
        return DB::transaction(function () use ($user, $organization, $reason) {
            $paused = [];

            Workflow::query()
                ->when($organization, fn ($q) => $q->where('organization_id', $organization->getKey()))
                ->whereIn('status', [WorkflowStatus::Pending, WorkflowStatus::Running])
                ->orderBy('id')
                ->get()
                ->each(function (Workflow $workflow) use (&$paused) {
                    $this->engine->pause($workflow, 'Paused by emergency stop');
                    $paused[] = $workflow->id;
                });

            // Running agent runs stop at their next safe point and keep their checkpoint.
            $pausedRuns = 0;
            AgentRun::query()
                ->when($organization, fn ($q) => $q->where('organization_id', $organization->getKey()))
                ->whereIn('status', [AgentRunStatus::Running, AgentRunStatus::Retrying])
                ->orderBy('id')
                ->get()
                ->each(function (AgentRun $run) use (&$pausedRuns) {
                    $this->runs->pause($run, 'emergency_stop');
                    $pausedRuns++;
                });

            // Merge with an existing halt so a second stop never "forgets" earlier paused work.
            $existing = $this->halt->state($organization?->getKey())['paused_workflow_ids'] ?? [];
            $this->halt->set($organization, $user, $reason, array_values(array_unique([...$existing, ...$paused])));

            $this->events->record('operations.halted', [
                'scope' => $organization ? 'organization' : 'global',
                'reason' => $reason,
                'paused_workflows' => count($paused),
                'paused_agent_runs' => $pausedRuns,
            ], organization: $organization, actor: $user);

            $this->audit->log('operations.halted', $user, $organization, metadata: [
                'reason' => $reason,
                'paused_workflow_ids' => $paused,
            ]);

            return $paused;
        });
    }
}
