<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\BudgetAlert;
use App\Models\TokenBudget;
use App\Models\TokenUsage;
use App\Reliability\AuditLogger;
use App\Reliability\EventRecorder;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CostDashboardController extends Controller
{
    public function __construct(
        private readonly EventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, CurrentOrganization $current): Response
    {
        $organization = $current->get();
        abort_unless($request->user()->hasPermissionIn($organization, Permission::AgentsView), 403);

        $projects = $organization->projects()->orderBy('name')->get(['id', 'key', 'name']);
        $tasks = $organization->projects()->with(['tasks' => fn ($query) => $query->latest('id')->limit(200)])->get()
            ->flatMap(fn ($project) => $project->tasks->map(fn ($task) => [
                'id' => $task->id,
                'label' => "{$project->key}-{$task->number} {$task->title}",
            ]))->values();
        $agents = $organization->agents()->orderBy('name')->get(['id', 'name']);
        $budgetLabels = [
            'organization' => [$organization->id => $organization->name],
            'project' => $projects->pluck('name', 'id')->all(),
            'task' => $tasks->pluck('label', 'id')->all(),
            'agent' => $agents->pluck('name', 'id')->all(),
        ];

        $budgets = TokenBudget::where('organization_id', $organization->id)->orderBy('scope_type')->orderBy('period')->get()
            ->map(fn (TokenBudget $budget) => [
                'id' => $budget->id,
                'scope_type' => $budget->scope_type,
                'scope_id' => (int) $budget->scope_id,
                'scope_label' => $budgetLabels[$budget->scope_type][$budget->scope_id] ?? 'Unavailable target',
                'period' => $budget->period,
                'max_tokens' => (int) $budget->max_tokens,
                'max_cost' => $budget->max_cost !== null ? (float) $budget->max_cost : null,
                'hard_stop' => (bool) $budget->hard_stop,
                'enabled' => (bool) $budget->enabled,
            ]);

        return Inertia::render('costs/index', [
            'currency' => 'USD',
            'summary' => [
                'today' => $this->summary($organization->id, now()->startOfDay()),
                'week' => $this->summary($organization->id, now()->startOfWeek()),
                'month' => $this->summary($organization->id, now()->startOfMonth()),
            ],
            'economics' => $this->economics($organization->id),
            'breakdowns' => $this->breakdowns($organization->id),
            'budgets' => $budgets,
            'targets' => [
                'organization' => [['id' => $organization->id, 'label' => $organization->name]],
                'project' => $projects->map(fn ($project) => ['id' => $project->id, 'label' => $project->name])->values(),
                'task' => $tasks,
                'agent' => $agents->map(fn ($agent) => ['id' => $agent->id, 'label' => $agent->name])->values(),
            ],
            'alerts' => BudgetAlert::where('organization_id', $organization->id)->latest('emitted_at')->limit(20)->get()->map(fn (BudgetAlert $alert) => [
                'id' => $alert->id,
                'scope' => $alert->scope,
                'metric' => $alert->metric,
                'threshold' => $alert->threshold,
                'used' => (float) $alert->used,
                'limit' => (float) $alert->limit_value,
                'hard_stop' => (bool) $alert->hard_stop,
                'emitted_at' => $alert->emitted_at?->toIso8601String(),
            ]),
            'can' => ['manage' => $request->user()->hasPermissionIn($organization, Permission::AgentsManage)],
        ]);
    }

    public function storeBudget(Request $request, CurrentOrganization $current): RedirectResponse
    {
        $organization = $current->get();
        abort_unless($request->user()->hasPermissionIn($organization, Permission::AgentsManage), 403);
        $data = $request->validate([
            'scope_type' => ['required', Rule::in(['organization', 'project', 'task', 'agent'])],
            'scope_id' => ['required', 'integer'],
            'period' => ['required', Rule::in(['day', 'month', 'all_time'])],
            'max_tokens' => ['required', 'integer', 'min:100', 'max:1000000000000'],
            'max_cost' => ['nullable', 'numeric', 'min:0.000001', 'max:100000000'],
            'hard_stop' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
        ]);
        abort_unless($this->targetExists($organization, $data['scope_type'], (int) $data['scope_id']), 404);

        DB::transaction(function () use ($organization, $data, $request) {
            $budget = TokenBudget::updateOrCreate([
                'organization_id' => $organization->id,
                'scope_type' => $data['scope_type'],
                'scope_id' => $data['scope_id'],
                'period' => $data['period'],
            ], [
                'max_tokens' => $data['max_tokens'],
                'max_cost' => $data['max_cost'] ?? null,
                'hard_stop' => $data['hard_stop'],
                'enabled' => $data['enabled'],
            ]);
            $this->events->record('budget.updated', ['budget_id' => $budget->id, 'scope' => "{$budget->scope_type}:{$budget->period}", 'hard_stop' => $budget->hard_stop], organization: $organization, actor: $request->user());
            $this->audit->log('budget.updated', $request->user(), $organization, subject: $budget, metadata: $budget->only('scope_type', 'scope_id', 'period', 'max_tokens', 'max_cost', 'hard_stop', 'enabled'));
        });

        return back()->with('status', 'Budget policy saved.');
    }

    public function destroyBudget(Request $request, TokenBudget $budget, CurrentOrganization $current): RedirectResponse
    {
        $organization = $current->get();
        abort_unless($request->user()->hasPermissionIn($organization, Permission::AgentsManage), 403);

        DB::transaction(function () use ($budget, $organization, $request) {
            $metadata = $budget->only('scope_type', 'scope_id', 'period');
            $this->events->record('budget.deleted', ['budget_id' => $budget->id, ...$metadata], organization: $organization, actor: $request->user());
            $this->audit->log('budget.deleted', $request->user(), $organization, subject: $budget, metadata: $metadata);
            $budget->delete();
        });

        return back()->with('status', 'Budget policy removed.');
    }

    /** @return array<string, int|float> */
    private function summary(int $organizationId, $since): array
    {
        $row = TokenUsage::where('organization_id', $organizationId)->where('recorded_at', '>=', $since)
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(cached_input_tokens), 0) as cached_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw('COALESCE(SUM(total_cost), 0) as cost')
            ->selectRaw('SUM(CASE WHEN total_cost IS NULL THEN 1 ELSE 0 END) as unpriced_calls')
            ->first();

        return [
            'input_tokens' => (int) $row->input_tokens,
            'cached_tokens' => (int) $row->cached_tokens,
            'output_tokens' => (int) $row->output_tokens,
            'total_tokens' => (int) $row->total_tokens,
            'cost' => round((float) $row->cost, 6),
            'unpriced_calls' => (int) $row->unpriced_calls,
        ];
    }

    /** @return array<string, float|int> */
    private function economics(int $organizationId): array
    {
        $month = TokenUsage::where('token_usage.organization_id', $organizationId)->where('recorded_at', '>=', now()->startOfMonth());
        $taskCount = (clone $month)->distinct()->count('task_id');
        $completed = (clone $month)->join('tasks', 'tasks.id', '=', 'token_usage.task_id')
            ->where('tasks.status', TaskStatus::Done->value)->where('tasks.type', TaskType::Feature->value);
        $completedCount = (clone $completed)->distinct()->count('token_usage.task_id');

        return [
            'average_cost_per_task' => $taskCount ? round((float) (clone $month)->sum('total_cost') / $taskCount, 6) : 0,
            'cost_per_completed_feature' => $completedCount ? round((float) $completed->sum('total_cost') / $completedCount, 6) : 0,
            'tasks' => $taskCount,
            'completed_features' => $completedCount,
        ];
    }

    /** @return array<string, mixed> */
    private function breakdowns(int $organizationId): array
    {
        $base = TokenUsage::where('token_usage.organization_id', $organizationId)->where('recorded_at', '>=', now()->startOfMonth());
        $columns = 'COALESCE(SUM(token_usage.total_tokens), 0) as tokens, COALESCE(SUM(token_usage.total_cost), 0) as cost, SUM(CASE WHEN token_usage.total_cost IS NULL THEN 1 ELSE 0 END) as unpriced_calls';

        $project = (clone $base)->join('projects', 'projects.id', '=', 'token_usage.project_id')
            ->select('projects.id', 'projects.name as label')->selectRaw($columns)->groupBy('projects.id', 'projects.name')->orderByDesc('cost')->get();
        $task = (clone $base)->join('tasks', 'tasks.id', '=', 'token_usage.task_id')->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->select('tasks.id', 'projects.key as project_key', 'tasks.number')->selectRaw($columns)
            ->groupBy('tasks.id', 'projects.key', 'tasks.number')->orderByDesc('cost')->limit(20)->get();
        $agent = (clone $base)->join('agents', 'agents.id', '=', 'token_usage.agent_id')
            ->select('agents.id', 'agents.name as label')->selectRaw($columns)->groupBy('agents.id', 'agents.name')->orderByDesc('cost')->get();
        $model = (clone $base)->leftJoin('ai_models', 'ai_models.id', '=', 'token_usage.ai_model_id')
            ->selectRaw("COALESCE(ai_models.id, 0) as id, COALESCE(ai_models.display_name, 'Deleted model') as label")
            ->selectRaw($columns)->groupBy('ai_models.id', 'ai_models.display_name')->orderByDesc('cost')->get();

        return collect(compact('project', 'task', 'agent', 'model'))->map(fn ($rows) => $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'label' => isset($row->project_key) ? "{$row->project_key}-{$row->number}" : $row->label,
            'tokens' => (int) $row->tokens,
            'cost' => round((float) $row->cost, 6),
            'unpriced_calls' => (int) $row->unpriced_calls,
        ])->values())->all();
    }

    private function targetExists($organization, string $type, int $id): bool
    {
        return match ($type) {
            'organization' => $id === $organization->id,
            'project' => $organization->projects()->whereKey($id)->exists(),
            'task' => $organization->projects()->whereHas('tasks', fn (Builder $query) => $query->whereKey($id))->exists(),
            'agent' => $organization->agents()->whereKey($id)->exists(),
            default => false,
        };
    }
}
