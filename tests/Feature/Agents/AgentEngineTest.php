<?php

namespace Tests\Feature\Agents;

use App\Actions\Operations\HaltOperations;
use App\Actions\Operations\ResumeOperations;
use App\Agents\AgentCatalog;
use App\Agents\AgentRunService;
use App\Enums\AgentRunStatus;
use App\Enums\AgentStatus;
use App\Enums\ModelTier;
use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TokenUsage;
use App\Models\User;
use App\Reliability\SystemHalt;
use App\Workflow\Exceptions\OperationsHaltedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AgentEngineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->memberOf($this->organization, OrganizationRole::Owner)->create();
        $this->project = Project::factory()->for($this->organization)->create(['key' => 'BOOK', 'slug' => 'booking']);
        $this->task = Task::factory()->for($this->project)->create(['status' => TaskStatus::InProgress, 'token_budget' => 50000]);
        app(AgentCatalog::class)->ensure($this->organization);
    }

    public function test_catalog_exposes_twelve_versioned_specialists_without_fake_runs(): void
    {
        $this->actingAs($this->owner)->get('/ai-team')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('agents/index')
            ->has('agents', 12)
            ->where('agents.0.role.key', 'PM')
            ->where('agents.0.skills.0.version', '1.0.0')
            ->has('routes', 12)
            ->has('runs', 0)
            ->where('can.manage', true));

        $this->assertDatabaseCount('agent_roles', 12);
        $this->assertDatabaseCount('skills', 12);
        $this->assertDatabaseCount('skill_versions', 12);
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_agent_definitions_are_tenant_scoped_and_permissioned(): void
    {
        $other = Organization::factory()->create();
        app(AgentCatalog::class)->ensure($other);
        $foreign = $other->agents()->firstOrFail();
        $viewer = User::factory()->memberOf($this->organization, OrganizationRole::Viewer)->create();
        $developer = User::factory()->memberOf($this->organization, OrganizationRole::Developer)->create();
        $mine = $this->organization->agents()->firstOrFail();

        $this->actingAs($viewer)->get('/ai-team')->assertOk();
        $this->actingAs($developer)->patch("/ai-team/agents/{$mine->id}", [
            'version' => $mine->version,
            'token_budget' => 4000,
            'retry_limit' => 2,
            'enabled' => true,
        ])->assertForbidden();
        $this->actingAs($this->owner)->patch("/ai-team/agents/{$foreign->id}", [
            'version' => $foreign->version,
            'token_budget' => 4000,
            'retry_limit' => 2,
            'enabled' => true,
        ])->assertNotFound();
    }

    public function test_a_run_snapshots_route_skills_redacts_secrets_and_is_idempotent(): void
    {
        $agent = $this->agent('BE');
        [$primary, $fallback] = $this->models();
        $route = $this->organization->modelRoutes()->where('agent_role_id', $agent->agent_role_id)->firstOrFail();
        $route->forceFill(['primary_model_id' => $primary->id, 'fallback_model_id' => $fallback->id])->save();

        $service = app(AgentRunService::class);
        $run = $service->start($this->task, $agent, 'implementation', 'BOOK-1:implement:1', [
            'instruction' => 'Use API_TOKEN=super-secret-token-value safely.',
        ]);
        $same = $service->start($this->task, $agent, 'implementation', 'BOOK-1:implement:1');

        $this->assertTrue($run->is($same));
        $this->assertSame($primary->id, $run->ai_model_id);
        $this->assertSame('Use API_TOKEN=[REDACTED] safely.', $run->input['instruction']);
        $this->assertSame(1, $run->skillVersions()->count());
        $this->assertSame(AgentStatus::Initializing, $agent->fresh()->status);
        $this->assertDatabaseHas('agent_events', ['agent_run_id' => $run->id, 'sequence' => 1, 'type' => 'agent.run.started']);
        $this->assertDatabaseHas('outbox_events', ['project_id' => $this->project->id, 'type' => 'agent.run.started']);
    }

    public function test_token_cap_is_a_hard_stop_and_usage_delivery_is_idempotent(): void
    {
        $agent = $this->agent('BE');
        $agent->forceFill(['token_budget' => 100])->save();
        $this->routeModels($agent);
        $run = app(AgentRunService::class)->start($this->task, $agent, 'implementation', 'budget-run');

        $service = app(AgentRunService::class);
        $first = $service->recordTokens($run, 80, 20, 30, 'provider-call-1');
        $same = $service->recordTokens($run, 80, 20, 30, 'provider-call-1');

        $this->assertTrue($first->is($same));
        $this->assertSame(1, TokenUsage::count());
        $this->assertSame(110, $run->fresh()->total_tokens);
        $this->assertSame(AgentRunStatus::Blocked, $run->fresh()->status);
        $this->assertSame(TaskStatus::Blocked, $this->task->fresh()->status);
        $this->assertDatabaseHas('agent_events', ['agent_run_id' => $run->id, 'type' => 'agent.run.budget_exhausted']);
    }

    public function test_owner_can_set_a_task_specific_token_cap(): void
    {
        $this->actingAs($this->owner)->patch('/projects/booking/tasks/'.$this->task->number, [
            'version' => $this->task->version,
            'token_budget' => 12345,
        ])->assertSessionHasNoErrors();

        $this->assertSame(12345, $this->task->fresh()->token_budget);
        $this->assertDatabaseHas('outbox_events', ['project_id' => $this->project->id, 'type' => 'task.updated']);
    }

    public function test_retry_limit_selects_fallback_then_blocks(): void
    {
        $agent = $this->agent('SE');
        $agent->forceFill(['retry_limit' => 2])->save();
        [$primary, $fallback] = $this->models(ModelTier::Advanced);
        $route = $this->organization->modelRoutes()->where('agent_role_id', $agent->agent_role_id)->firstOrFail();
        $route->forceFill(['primary_model_id' => $primary->id, 'fallback_model_id' => $fallback->id])->save();

        $service = app(AgentRunService::class);
        $run = $service->start($this->task, $agent, 'security', 'retry-run');
        $service->fail($run, 'provider timeout');

        $this->assertSame(AgentRunStatus::Retrying, $run->fresh()->status);
        $this->assertSame(2, $run->fresh()->attempt);
        $this->assertSame($fallback->id, $run->fresh()->ai_model_id);

        $service->fail($run, 'provider timeout again');
        $this->assertSame(AgentRunStatus::Blocked, $run->fresh()->status);
        $this->assertSame(TaskStatus::Blocked, $this->task->fresh()->status);
        $this->assertSame(2, $run->events()->where('type', 'like', 'agent.run.%')->whereIn('type', ['agent.run.retrying', 'agent.run.retry_limit_reached'])->count());
    }

    public function test_a_run_needs_a_configured_model_and_running_operations(): void
    {
        $agent = $this->agent('BE');

        try {
            app(AgentRunService::class)->start($this->task, $agent, 'implementation', 'no-model');
            $this->fail('a run without a model must be refused');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('No AI model is configured', $e->getMessage());
        }

        $this->routeModels($agent);
        app(SystemHalt::class)->set($this->organization, $this->owner, 'test', []);

        $this->expectException(OperationsHaltedException::class);
        app(AgentRunService::class)->start($this->task, $agent, 'implementation', 'halted');
    }

    public function test_activity_reports_drive_the_agent_state_and_live_events(): void
    {
        $agent = $this->agent('BE');
        $this->routeModels($agent);
        $service = app(AgentRunService::class);
        $run = $service->start($this->task, $agent, 'implementation', 'activity-run');

        $service->report($run, AgentStatus::Reading, 'Reading BookingService.php', ['file' => 'app/Services/BookingService.php']);
        $this->assertSame(AgentStatus::Reading, $agent->fresh()->status);
        $this->assertSame('Reading BookingService.php', $run->fresh()->activity);

        $service->report($run, AgentStatus::Testing, 'Running Pest — 118/120 passed', ['passed' => 118, 'total' => 120]);
        $this->assertSame(AgentStatus::Testing, $agent->fresh()->status);
        $this->assertTrue($agent->fresh()->status->isActive());
        $this->assertDatabaseHas('outbox_events', ['project_id' => $this->project->id, 'type' => 'agent.status.changed']);
        $this->assertSame(3, $run->events()->count(), 'started + two activity events, each sequence-numbered');

        $this->expectException(\DomainException::class);
        $service->report($run, AgentStatus::Completed, 'claims to be done');
    }

    public function test_the_emergency_stop_pauses_runs_and_resume_restores_only_those(): void
    {
        $be = $this->agent('BE');
        $fe = $this->agent('FE');
        $this->routeModels($be);
        $this->routeModels($fe);
        $service = app(AgentRunService::class);
        $stopped = $service->start($this->task, $be, 'implementation', 'run-be');
        $ownerPaused = $service->start($this->task, $fe, 'implementation', 'run-fe');
        $service->pause($ownerPaused, 'owner');

        app(HaltOperations::class)->handle($this->owner, $this->organization, 'Checking agent output');

        $this->assertSame(AgentRunStatus::Paused, $stopped->fresh()->status);
        $this->assertSame(AgentStatus::Paused, $be->fresh()->status);
        try {
            $service->report($stopped->fresh(), AgentStatus::Working, 'still going?');
            $this->fail('a paused run cannot report activity');
        } catch (\DomainException) {
        }

        app(ResumeOperations::class)->handle($this->owner, $this->organization);

        $this->assertSame(AgentRunStatus::Running, $stopped->fresh()->status);
        $this->assertSame(AgentStatus::Initializing, $be->fresh()->status);
        $this->assertSame(AgentRunStatus::Paused, $ownerPaused->fresh()->status, 'a run the owner paused stays paused');
    }

    public function test_a_stalled_run_counts_as_a_failed_attempt(): void
    {
        $agent = $this->agent('BE');
        $this->routeModels($agent);
        $service = app(AgentRunService::class);
        $run = $service->start($this->task, $agent, 'implementation', 'stall-run');

        $this->travel(10)->minutes();
        $this->assertSame(1, $service->recoverStalled());

        $this->assertSame(AgentRunStatus::Retrying, $run->fresh()->status);
        $this->assertSame(AgentStatus::Queued, $agent->fresh()->status);
        $this->assertStringContainsString('Stalled', $run->fresh()->last_error);
    }

    public function test_model_routes_of_other_organizations_cannot_be_changed(): void
    {
        $other = Organization::factory()->create();
        app(AgentCatalog::class)->ensure($other);
        $foreign = $other->modelRoutes()->firstOrFail();

        $this->actingAs($this->owner)->patch("/ai-team/routes/{$foreign->id}", [
            'version' => $foreign->version,
            'tier' => 'fast',
            'enabled' => false,
        ])->assertNotFound();

        $this->assertTrue($foreign->fresh()->enabled);
    }

    private function routeModels(Agent $agent): void
    {
        $route = $this->organization->modelRoutes()->where('agent_role_id', $agent->agent_role_id)->firstOrFail();
        [$primary, $fallback] = $this->models($route->tier, $agent->role->key);
        $route->forceFill(['primary_model_id' => $primary->id, 'fallback_model_id' => $fallback->id])->save();
    }

    /** @return array{AiModel, AiModel} */
    private function models(ModelTier $tier = ModelTier::Standard, string $suffix = ''): array
    {
        $primary = AiModel::forceCreate(['organization_id' => $this->organization->id, 'provider' => 'provider-a', 'model_key' => 'primary-'.$tier->value.$suffix, 'display_name' => 'Primary', 'tier' => $tier]);
        $fallback = AiModel::forceCreate(['organization_id' => $this->organization->id, 'provider' => 'provider-b', 'model_key' => 'fallback-'.$tier->value.$suffix, 'display_name' => 'Fallback', 'tier' => $tier]);

        return [$primary, $fallback];
    }

    private function agent(string $role): Agent
    {
        return $this->organization->agents()->whereHas('role', fn ($query) => $query->where('key', $role))->firstOrFail();
    }
}
