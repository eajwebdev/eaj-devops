<?php

namespace App\Http\Controllers;

use App\Agents\AgentCatalog;
use App\Enums\AgentRunStatus;
use App\Enums\ModelTier;
use App\Enums\Permission;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\ModelRoute;
use App\Models\Skill;
use App\Reliability\AuditLogger;
use App\Reliability\EventRecorder;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AgentTeamController extends Controller
{
    public function __construct(
        private readonly AgentCatalog $catalog,
        private readonly EventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, CurrentOrganization $current): Response
    {
        $organization = $current->get();
        abort_unless($request->user()->hasPermissionIn($organization, Permission::AgentsView), 403);
        $this->catalog->ensure($organization);

        $agents = $organization->agents()
            ->with([
                'role',
                'skills.versions',
                'runs' => fn ($q) => $q->with(['agent.role', 'task.project', 'model'])->latest('id')->limit(1),
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'slug' => $agent->slug,
                'role' => [
                    'key' => $agent->role->key,
                    'name' => $agent->role->name,
                    'description' => $agent->role->description,
                    'responsibilities' => $agent->role->responsibilities,
                    'may_implement' => $agent->role->may_implement,
                ],
                'status' => $agent->status->value,
                'token_budget' => $agent->token_budget,
                'retry_limit' => $agent->retry_limit,
                'max_wall_seconds' => $agent->max_wall_seconds,
                'enabled' => $agent->enabled,
                'version' => $agent->version,
                'skills' => $agent->skills->map(fn (Skill $skill) => [
                    'id' => $skill->id,
                    'name' => $skill->name,
                    'enabled' => (bool) $skill->pivot->enabled && $skill->enabled,
                    'version' => $skill->versions->firstWhere('id', $skill->pivot->skill_version_id)?->version,
                ])->values(),
                'last_run' => $agent->runs->first() ? $this->run($agent->runs->first()) : null,
            ]);

        $routes = $organization->modelRoutes()
            ->with(['role', 'primaryModel', 'fallbackModel'])
            ->orderBy('id')
            ->get()
            ->map(fn (ModelRoute $route) => [
                'id' => $route->id,
                'route_key' => $route->route_key,
                'role' => $route->role?->key,
                'role_name' => $route->role?->name ?? 'Organization default',
                'purpose' => $route->purpose,
                'tier' => $route->tier->value,
                'primary_model_id' => $route->primary_model_id,
                'primary_model' => $route->primaryModel?->display_name,
                'fallback_model_id' => $route->fallback_model_id,
                'fallback_model' => $route->fallbackModel?->display_name,
                'enabled' => $route->enabled,
                'version' => $route->version,
            ]);

        $models = $organization->aiModels()->orderBy('tier')->orderBy('display_name')->get()->map(fn (AiModel $model) => [
            'id' => $model->id,
            'provider' => $model->provider,
            'model_key' => $model->model_key,
            'display_name' => $model->display_name,
            'tier' => $model->tier->value,
            'max_context' => $model->max_context,
            'input_cost' => $model->input_cost !== null ? (float) $model->input_cost : null,
            'cached_input_cost' => $model->cached_input_cost !== null ? (float) $model->cached_input_cost : null,
            'output_cost' => $model->output_cost !== null ? (float) $model->output_cost : null,
            'enabled' => $model->enabled,
        ]);

        $runs = $organization->agentRuns()
            ->with(['agent.role', 'task.project', 'model'])
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn ($run) => $this->run($run));

        return Inertia::render('agents/index', [
            'agents' => $agents,
            'routes' => $routes,
            'models' => $models,
            'runs' => $runs,
            'tiers' => array_map(fn (ModelTier $tier) => ['value' => $tier->value, 'label' => $tier->label()], ModelTier::cases()),
            'can' => ['manage' => $request->user()->hasPermissionIn($organization, Permission::AgentsManage)],
        ]);
    }

    public function updateAgent(Request $request, Agent $agent): RedirectResponse
    {
        $organization = app(CurrentOrganization::class)->get();
        abort_unless($request->user()->hasPermissionIn($organization, Permission::AgentsManage), 403);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'token_budget' => ['required', 'integer', 'min:100', 'max:10000000'],
            'retry_limit' => ['required', 'integer', 'min:1', 'max:10'],
            'enabled' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($agent, $data, $request, $organization) {
            $locked = Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            if ($locked->version !== (int) $data['version']) {
                abort(409, 'This agent changed meanwhile. Reload and try again.');
            }
            if (! $data['enabled'] && $locked->runs()->whereIn('status', [AgentRunStatus::Running, AgentRunStatus::Retrying])->exists()) {
                abort(422, 'A working agent cannot be disabled.');
            }

            $locked->forceFill([
                'token_budget' => $data['token_budget'],
                'retry_limit' => $data['retry_limit'],
                'enabled' => $data['enabled'],
                'version' => $locked->version + 1,
            ])->save();
            $this->events->record('agent.definition.updated', ['agent_id' => $locked->id, 'role' => $locked->role->key], organization: $organization, actor: $request->user());
            $this->audit->log('agent.definition.updated', $request->user(), $organization, subject: $locked, metadata: $locked->only('token_budget', 'retry_limit', 'enabled'));
        });

        return back()->with('status', 'Agent limits updated.');
    }

    public function updateSkill(Request $request, Skill $skill): RedirectResponse
    {
        $organization = app(CurrentOrganization::class)->get();
        abort_unless($request->user()->hasPermissionIn($organization, Permission::AgentsManage), 403);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        DB::transaction(function () use ($skill, $data, $request, $organization) {
            $skill->forceFill(['enabled' => $data['enabled']])->save();
            $this->events->record('skill.updated', ['skill_id' => $skill->id, 'enabled' => $skill->enabled], organization: $organization, actor: $request->user());
            $this->audit->log('skill.updated', $request->user(), $organization, subject: $skill, metadata: ['enabled' => $skill->enabled]);
        });

        return back()->with('status', 'Skill availability updated.');
    }

    public function storeModel(Request $request): RedirectResponse
    {
        $organization = app(CurrentOrganization::class)->get();
        abort_unless($request->user()->hasPermissionIn($organization, Permission::AgentsManage), 403);
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:64'],
            'model_key' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'display_name' => ['required', 'string', 'max:120'],
            'tier' => ['required', Rule::enum(ModelTier::class)],
            'max_context' => ['nullable', 'integer', 'min:1000'],
            'input_cost' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'cached_input_cost' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'output_cost' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        if ($organization->aiModels()->where('provider', $data['provider'])->where('model_key', $data['model_key'])->exists()) {
            return back()->withErrors(['model_key' => 'That provider model is already registered.']);
        }

        $model = $organization->aiModels()->create($data);
        $this->events->record('ai_model.created', ['model_id' => $model->id, 'tier' => $model->tier->value], organization: $organization, actor: $request->user());
        $this->audit->log('ai_model.created', $request->user(), $organization, subject: $model, metadata: ['provider' => $model->provider, 'model_key' => $model->model_key]);

        return back()->with('status', 'Model registered for routing.');
    }

    public function updateModel(Request $request, AiModel $aiModel): RedirectResponse
    {
        $organization = app(CurrentOrganization::class)->get();
        abort_unless($request->user()->hasPermissionIn($organization, Permission::AgentsManage), 403);
        $data = $request->validate([
            'input_cost' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'cached_input_cost' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'output_cost' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'enabled' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($aiModel, $data, $request, $organization) {
            $aiModel->forceFill($data)->save();
            $this->events->record('ai_model.pricing_updated', [
                'model_id' => $aiModel->id,
                'priced' => $aiModel->input_cost !== null && $aiModel->cached_input_cost !== null && $aiModel->output_cost !== null,
            ], organization: $organization, actor: $request->user());
            $this->audit->log('ai_model.pricing_updated', $request->user(), $organization, subject: $aiModel, metadata: $aiModel->only('input_cost', 'cached_input_cost', 'output_cost', 'enabled'));
        });

        return back()->with('status', 'Model pricing updated. Historical usage keeps its original price snapshot.');
    }

    public function updateRoute(Request $request, ModelRoute $modelRoute): RedirectResponse
    {
        $organization = app(CurrentOrganization::class)->get();
        abort_unless($request->user()->hasPermissionIn($organization, Permission::AgentsManage), 403);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'tier' => ['required', Rule::enum(ModelTier::class)],
            'primary_model_id' => ['nullable', 'integer'],
            'fallback_model_id' => ['nullable', 'integer', 'different:primary_model_id'],
            'enabled' => ['required', 'boolean'],
        ]);
        $ids = array_values(array_filter([$data['primary_model_id'] ?? null, $data['fallback_model_id'] ?? null]));
        if ($organization->aiModels()->whereKey($ids)->count() !== count($ids)) {
            return back()->withErrors(['route' => 'Choose models from this organization.']);
        }

        DB::transaction(function () use ($modelRoute, $data, $request, $organization) {
            $locked = ModelRoute::whereKey($modelRoute->id)->lockForUpdate()->firstOrFail();
            if ($locked->version !== (int) $data['version']) {
                abort(409, 'This route changed meanwhile. Reload and try again.');
            }
            $locked->forceFill([
                'tier' => $data['tier'],
                'primary_model_id' => $data['primary_model_id'] ?? null,
                'fallback_model_id' => $data['fallback_model_id'] ?? null,
                'enabled' => $data['enabled'],
                'version' => $locked->version + 1,
            ])->save();
            $this->events->record('model_route.updated', ['route_id' => $locked->id, 'tier' => $locked->tier->value], organization: $organization, actor: $request->user());
            $this->audit->log('model_route.updated', $request->user(), $organization, subject: $locked, metadata: $locked->only('tier', 'primary_model_id', 'fallback_model_id', 'enabled'));
        });

        return back()->with('status', 'Model route updated.');
    }

    /** @return array<string, mixed> */
    private function run($run): array
    {
        return [
            'id' => $run->public_id,
            'agent' => $run->agent->name,
            'role' => $run->agent->role->key,
            'task_key' => $run->task->key(),
            'task_title' => $run->task->title,
            'project_slug' => $run->task->project->slug,
            'status' => $run->status->value,
            'purpose' => $run->purpose,
            'attempt' => $run->attempt,
            'max_attempts' => $run->max_attempts,
            'total_tokens' => $run->total_tokens,
            'total_cost' => (float) $run->total_cost,
            'token_budget' => $run->token_budget,
            'model' => $run->model?->display_name,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
