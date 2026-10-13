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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lifts an emergency stop. Resuming is always an explicit owner action, and
 * preview() shows exactly which workflows will resume before it happens.
 */
class ResumeOperations
{
    public function __construct(
        private readonly SystemHalt $halt,
        private readonly WorkflowEngine $engine,
        private readonly EventRecorder $events,
        private readonly AuditLogger $audit,
        private readonly AgentRunService $runs,
    ) {}

    /**
     * Workflows paused by the stop that are still paused and will resume.
     *
     * @return Collection<int, Workflow>
     */
    public function preview(?Organization $organization): Collection
    {
        $ids = $this->halt->state($organization?->getKey())['paused_workflow_ids'] ?? [];

        return Workflow::query()
            ->whereKey($ids)
            ->where('status', WorkflowStatus::Paused)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<int> IDs of the workflows that were resumed
     */
    public function handle(User $user, ?Organization $organization): array
    {
        return DB::transaction(function () use ($user, $organization) {
            if ($this->halt->state($organization?->getKey()) === null) {
                return [];
            }

            $workflows = $this->preview($organization);
            $this->halt->clear($organization);

            $resumed = [];

            foreach ($workflows as $workflow) {
                // Another stop still in force keeps it paused; hand it over so that stop's resume picks it up.
                if ($this->halt->isHalted($workflow->organization_id)) {
                    $holder = $this->halt->state(null) !== null ? null : $workflow->organization_id;
                    $this->halt->addPausedWorkflows($holder, [$workflow->id]);

                    continue;
                }

                $this->engine->resume($workflow, 'Resumed after emergency stop');
                $resumed[] = $workflow->id;
            }

            // Agent runs the emergency stop paused (never those an owner paused on purpose).
            AgentRun::query()
                ->when($organization, fn ($q) => $q->where('organization_id', $organization->getKey()))
                ->where('status', AgentRunStatus::Paused)
                ->where('pause_reason', 'emergency_stop')
                ->get()
                ->reject(fn (AgentRun $run) => $this->halt->isHalted($run->organization_id))
                ->each(fn (AgentRun $run) => $this->runs->resume($run));

            $this->events->record('operations.resumed', [
                'scope' => $organization ? 'organization' : 'global',
                'resumed_workflows' => count($resumed),
            ], organization: $organization, actor: $user);

            $this->audit->log('operations.resumed', $user, $organization, metadata: ['resumed_workflow_ids' => $resumed]);

            return $resumed;
        });
    }
}
