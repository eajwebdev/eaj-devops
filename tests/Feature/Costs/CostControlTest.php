<?php

namespace Tests\Feature\Costs;

use App\Agents\AgentCatalog;
use App\Agents\AgentRunService;
use App\Enums\AgentRunStatus;
use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\BudgetAlert;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TokenBudget;
use App\Models\TokenUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CostControlTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Project $project;

    private Task $task;

    private Agent $agent;

    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->memberOf($this->organization, OrganizationRole::Owner)->create();
        $this->project = Project::factory()->for($this->organization)->create(['key' => 'COST', 'slug' => 'costs']);
        $this->task = Task::factory()->for($this->project)->create(['status' => TaskStatus::InProgress, 'token_budget' => 100000]);
        app(AgentCatalog::class)->ensure($this->organization);
        $this->agent = $this->organization->agents()->whereHas('role', fn ($query) => $query->where('key', 'BE'))->firstOrFail();
        $this->agent->forceFill(['token_budget' => 100000])->save();
        $this->model = AiModel::forceCreate([
            'organization_id' => $this->organization->id,
            'provider' => 'priced-provider',
            'model_key' => 'priced-model',
            'display_name' => 'Priced model',
            'tier' => 'standard',
            'input_cost' => 10,
            'cached_input_cost' => 2,
            'output_cost' => 30,
        ]);
        $this->organization->modelRoutes()->where('agent_role_id', $this->agent->agent_role_id)->update(['primary_model_id' => $this->model->id]);
    }

    public function test_usage_prices_cached_tokens_separately_and_snapshots_the_rate(): void
    {
        $run = app(AgentRunService::class)->start($this->task, $this->agent, 'implementation', 'priced-run');
        $usage = app(AgentRunService::class)->recordTokens($run, 1000, 400, 200, 'call-1');

        $this->assertSame('0.006000', $usage->input_cost);
        $this->assertSame('0.000800', $usage->cached_input_cost);
        $this->assertSame('0.006000', $usage->output_cost);
        $this->assertSame('0.012800', $usage->total_cost);
        $this->assertEquals(2.0, $usage->pricing_snapshot['cached_input_per_million']);
        $this->assertSame('0.012800', $run->fresh()->total_cost);

        $this->model->forceFill(['input_cost' => 999])->save();
        $this->assertSame('0.012800', $usage->fresh()->total_cost, 'historical spend is immutable');
    }

    public function test_threshold_alerts_are_emitted_once_and_one_hundred_percent_stops_the_run(): void
    {
        $this->agent->forceFill(['token_budget' => 1000])->save();
        $run = app(AgentRunService::class)->start($this->task, $this->agent, 'implementation', 'alert-run');
        $service = app(AgentRunService::class);

        $service->recordTokens($run, 700, 0, 0, 'call-1');
        $service->recordTokens($run, 200, 0, 0, 'call-2');
        $service->recordTokens($run, 100, 0, 0, 'call-3');

        $this->assertSame([70, 90, 100], BudgetAlert::where('budget_key', "run:{$run->id}")->orderBy('threshold')->pluck('threshold')->all());
        $this->assertSame(AgentRunStatus::Blocked, $run->fresh()->status);
        $this->assertSame(TaskStatus::Blocked, $this->task->fresh()->status);
        $this->assertSame(3, BudgetAlert::where('budget_key', "run:{$run->id}")->count());
        $this->assertDatabaseHas('outbox_events', ['project_id' => $this->project->id, 'type' => 'budget.warning']);
    }

    public function test_project_cost_policy_is_a_hard_stop_and_alert_only_mode_does_not_stop(): void
    {
        TokenBudget::forceCreate([
            'organization_id' => $this->organization->id,
            'scope_type' => 'project',
            'scope_id' => $this->project->id,
            'period' => 'month',
            'max_tokens' => 1000000,
            'max_cost' => 0.01,
            'hard_stop' => true,
        ]);
        $run = app(AgentRunService::class)->start($this->task, $this->agent, 'implementation', 'cost-stop');
        app(AgentRunService::class)->recordTokens($run, 1000, 0, 100, 'call-1');

        $this->assertSame(AgentRunStatus::Blocked, $run->fresh()->status);
        $this->assertStringContainsString('Cost budget reached', $run->fresh()->last_error);

        $task = Task::factory()->for($this->project)->create(['status' => TaskStatus::InProgress, 'token_budget' => 100000]);
        $otherAgent = $this->organization->agents()->whereHas('role', fn ($query) => $query->where('key', 'FE'))->firstOrFail();
        $otherAgent->forceFill(['token_budget' => 100000])->save();
        $this->organization->modelRoutes()->where('agent_role_id', $otherAgent->agent_role_id)->update(['primary_model_id' => $this->model->id]);
        TokenBudget::where('organization_id', $this->organization->id)->update(['hard_stop' => false]);
        $run = app(AgentRunService::class)->start($task, $otherAgent, 'implementation', 'cost-warning');
        app(AgentRunService::class)->recordTokens($run, 1000, 0, 100, 'call-2');

        $this->assertSame(AgentRunStatus::Running, $run->fresh()->status);
    }

    public function test_owner_manages_scoped_budgets_and_viewers_can_only_read_the_dashboard(): void
    {
        $viewer = User::factory()->memberOf($this->organization, OrganizationRole::Viewer)->create();
        $payload = ['scope_type' => 'project', 'scope_id' => $this->project->id, 'period' => 'month', 'max_tokens' => 500000, 'max_cost' => 25, 'hard_stop' => true, 'enabled' => true];

        $this->actingAs($viewer)->get('/costs')->assertOk()->assertInertia(fn (Assert $page) => $page->component('costs/index')->where('can.manage', false));
        $this->actingAs($viewer)->post('/costs/budgets', $payload)->assertForbidden();
        $this->actingAs($this->owner)->post('/costs/budgets', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('token_budgets', ['scope_type' => 'project', 'scope_id' => $this->project->id, 'max_tokens' => 500000]);

        $other = Organization::factory()->create();
        $foreignProject = Project::factory()->for($other)->create();
        $this->actingAs($this->owner)->post('/costs/budgets', [...$payload, 'scope_id' => $foreignProject->id])->assertNotFound();
    }

    public function test_model_pricing_is_tenant_scoped_and_visible_on_the_cost_dashboard(): void
    {
        $this->actingAs($this->owner)->patch("/ai-team/models/{$this->model->id}", [
            'input_cost' => 4.5,
            'cached_input_cost' => 1.25,
            'output_cost' => 12,
            'enabled' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame('1.250000', $this->model->fresh()->cached_input_cost);

        $run = app(AgentRunService::class)->start($this->task, $this->agent, 'implementation', 'dashboard-run');
        app(AgentRunService::class)->recordTokens($run, 1000, 100, 100, 'dashboard-call');
        $this->actingAs($this->owner)->get('/costs')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('costs/index')
            ->where('summary.month.total_tokens', 1100)
            ->where('summary.month.cached_tokens', 100)
            ->has('breakdowns.project', 1)
            ->where('breakdowns.project.0.label', $this->project->name));

        $this->assertSame(1, TokenUsage::count());
    }
}
