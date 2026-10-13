<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Enums\WorkflowStatus;
use App\Http\Presenters\ProjectPresenter;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workflow;
use App\Reliability\HealthCheck;
use App\Support\CurrentOrganization;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Executive dashboard (Plan §28). Every number here is computed from
 * persisted state — nothing is simulated (Plan §2.1).
 */
class CommandCenterController extends Controller
{
    public function __invoke(CurrentOrganization $current, HealthCheck $health): Response
    {
        $organization = $current->get();

        $taskCounts = Task::query()
            ->where('organization_id', $organization->getKey())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $blockedWorkflows = Workflow::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', WorkflowStatus::Blocked)
            ->count();
        $projects = $organization->projects()
            ->with('connection')
            ->where('status', 'active')
            ->latest('updated_at')
            ->get();

        $recent = OutboxEvent::query()
            ->where('organization_id', $organization->getKey())
            ->whereNotIn('type', OutboxEvent::QUIET_TYPES)
            ->with('actor:id,name')
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        return Inertia::render('command-center', [
            'metrics' => [
                'active_projects' => $projects->count(),
                'open_tasks' => (int) $taskCounts->except([TaskStatus::Done->value, TaskStatus::Cancelled->value])->sum(),
                // Blocked tasks plus workflows that hit a limit: everything waiting on a person (§28).
                'needs_decision' => (int) ($taskCounts[TaskStatus::Blocked->value] ?? 0) + $blockedWorkflows,
                'completed_today' => Task::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('status', TaskStatus::Done)
                    ->where('completed_at', '>=', now()->startOfDay())
                    ->count(),
            ],
            'projects' => $projects->take(6)->map(fn (Project $p) => ProjectPresenter::make($p))->values(),
            'activity' => $recent->reverse()->values()->map->toClientPayload(),
            'health' => $health->run($organization->getKey()),
        ]);
    }
}
