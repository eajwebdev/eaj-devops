<?php

namespace App\Http\Controllers;

use App\Agents\AgentCatalog;
use App\Enums\AgentRunStatus;
use App\Enums\AgentStatus;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarRoomController extends Controller
{
    public function __construct(private readonly AgentCatalog $catalog) {}

    public function __invoke(Request $request, CurrentOrganization $current): Response
    {
        $organization = $current->get();
        abort_unless($request->user()->hasPermissionIn($organization, Permission::AgentsView), 403);
        $this->catalog->ensure($organization);

        $projects = $organization->projects()->where('status', 'active')->orderBy('name')->get(['id', 'key', 'name', 'slug']);
        $project = $projects->firstWhere('slug', $request->query('project')) ?? $projects->first();

        $latestRuns = $project
            ? $project->agentRuns()
                ->with(['task.project', 'model', 'latestEvent'])
                ->latest('id')
                ->get()
                ->unique('agent_id')
                ->keyBy('agent_id')
            : collect();

        $agents = $organization->agents()->with('role')->orderBy('id')->get()
            ->map(fn (Agent $agent) => $this->agent($agent, $latestRuns->get($agent->id)))
            // §25: working agents sort to the top; finished agents sink.
            ->sortBy(fn ($agent) => match (true) {
                AgentStatus::tryFrom($agent['status'])?->isActive() ?? false => 0,
                in_array($agent['status'], ['STALLED', 'BLOCKED', 'FAILED'], true) => 1,
                in_array($agent['status'], ['QUEUED', 'WAITING_FOR_TOOL', 'WAITING_FOR_AGENT', 'PAUSED'], true) => 2,
                $agent['status'] === 'IDLE' => 3,
                default => 4,
            })->values();

        $activity = $project
            ? OutboxEvent::where('stream', $project->eventStream())
                ->whereNotIn('type', OutboxEvent::QUIET_TYPES)
                ->with('actor:id,name')
                ->latest('sequence')
                ->limit(100)
                ->get()
                ->reverse()
                ->values()
                ->map->toClientPayload()
            : collect();

        return Inertia::render('war-room', [
            'projects' => $projects,
            'project' => $project,
            'agents' => $agents,
            'activity' => $activity,
            'metrics' => $project ? $this->metrics($project) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function agent(Agent $agent, ?AgentRun $run): array
    {
        $running = $run?->status === AgentRunStatus::Running;
        $stalled = $running && $run->heartbeat_at?->lt(now()->subSeconds((int) config('eaj.heartbeat.stall_seconds', 90)));

        // The §32 state the agent last reported drives the §7.6 motion; lifecycle
        // states come from the run. STALLED overlays a running agent gone quiet (§48.5).
        $status = match (true) {
            ! $run => AgentStatus::Idle->value,
            $stalled => 'STALLED',
            $running => $agent->status->value,
            $run->status === AgentRunStatus::Retrying, $run->status === AgentRunStatus::Pending => AgentStatus::Queued->value,
            $run->status === AgentRunStatus::Paused => AgentStatus::Paused->value,
            $run->status === AgentRunStatus::Blocked => AgentStatus::Blocked->value,
            $run->status === AgentRunStatus::Failed => AgentStatus::Failed->value,
            $run->status === AgentRunStatus::Cancelled => AgentStatus::Cancelled->value,
            default => AgentStatus::Completed->value,
        };

        // Detail of the last activity report: the file being read, test counts, etc.
        $progress = $run?->latestEvent?->type === 'agent.status.changed' ? ($run->latestEvent->metadata ?? []) : [];

        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'role' => $agent->role->key,
            'status' => $status,
            'enabled' => $agent->enabled,
            'task' => $run ? ['number' => $run->task->number, 'key' => $run->task->key(), 'title' => $run->task->title] : null,
            'activity' => $run?->activity ?? $run?->latestEvent?->message,
            'progress' => [
                'file' => isset($progress['file']) ? (string) $progress['file'] : null,
                'passed' => isset($progress['passed']) ? (int) $progress['passed'] : null,
                'failed' => isset($progress['failed']) ? (int) $progress['failed'] : null,
                'total' => isset($progress['total']) ? (int) $progress['total'] : null,
            ],
            'model' => $run?->model?->display_name,
            'attempt' => $run?->attempt,
            'max_attempts' => $run?->max_attempts,
            'tokens' => $run?->total_tokens ?? 0,
            'token_budget' => $run?->token_budget ?? $agent->token_budget,
            'heartbeat_at' => $run?->heartbeat_at?->toIso8601String(),
            'started_at' => $run?->started_at?->toIso8601String(),
        ];
    }

    /** @return array{progress: int, tasks_done: int, tasks_total: int, active_runs: int, stalled_runs: int, tokens: int, token_budget: int} */
    private function metrics(Project $project): array
    {
        $tasks = $project->tasks()->where('status', '!=', TaskStatus::Cancelled)->get(['id', 'status']);
        $runs = $project->agentRuns()->get(['status', 'total_tokens', 'token_budget', 'heartbeat_at']);
        $active = $runs->filter(fn (AgentRun $run) => in_array($run->status, [AgentRunStatus::Running, AgentRunStatus::Retrying], true));
        $done = $tasks->where('status', TaskStatus::Done)->count();

        return [
            'progress' => $tasks->isEmpty() ? 0 : (int) round(($done / $tasks->count()) * 100),
            'tasks_done' => $done,
            'tasks_total' => $tasks->count(),
            'active_runs' => $active->count(),
            'stalled_runs' => $active->filter(fn (AgentRun $run) => $run->heartbeat_at?->lt(now()->subSeconds((int) config('eaj.heartbeat.stall_seconds', 90))))->count(),
            'tokens' => (int) $runs->sum('total_tokens'),
            'token_budget' => (int) $runs->sum('token_budget'),
        ];
    }
}
