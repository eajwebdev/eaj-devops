<?php

namespace Tests\Feature\Realtime;

use App\Agents\AgentCatalog;
use App\Agents\AgentRunService;
use App\Enums\AgentRunStatus;
use App\Enums\AgentStatus;
use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WarRoomTest extends TestCase
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
        $this->owner = User::factory()->memberOf($this->organization)->create();
        $this->project = Project::factory()->for($this->organization)->create([
            'key' => 'LIVE',
            'name' => 'Realtime Studio',
            'slug' => 'realtime-studio',
        ]);
        $this->task = Task::factory()->for($this->project)->create([
            'status' => TaskStatus::InProgress,
            'title' => 'Wire the durable event stream',
        ]);

        app(AgentCatalog::class)->ensure($this->organization);
    }

    public function test_war_room_renders_persisted_presence_activity_and_metrics(): void
    {
        Task::factory()->for($this->project)->status(TaskStatus::Done)->create();
        $agent = $this->configuredAgent('BE');
        $run = app(AgentRunService::class)->start($this->task, $agent, 'implementation', 'war-room-run');
        app(AgentRunService::class)->report($run, AgentStatus::Testing, 'Running the realtime acceptance suite', ['passed' => 118, 'total' => 120]);

        $this->actingAs($this->owner)
            ->get('/war-room?project=realtime-studio')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('war-room')
                ->where('project.id', $this->project->id)
                ->where('metrics.progress', 50)
                ->where('metrics.active_runs', 1)
                ->where('metrics.stalled_runs', 0)
                ->where('agents.0.role', 'BE')
                // The §32 state the agent reported, not a generic 'working' (§7.6 motion is bound to it).
                ->where('agents.0.status', 'TESTING')
                ->where('agents.0.activity', 'Running the realtime acceptance suite')
                ->where('agents.0.progress.passed', 118)
                ->where('agents.0.progress.total', 120)
                ->where('agents.0.task.key', 'LIVE-'.$this->task->number)
                ->has('activity', 2));
    }

    public function test_each_lifecycle_maps_to_its_agent_state(): void
    {
        $service = app(AgentRunService::class);
        $be = $this->configuredAgent('BE');
        $fe = $this->configuredAgent('FE');
        $qa = $this->configuredAgent('QA');

        $reading = $service->start($this->task, $be, 'implementation', 'map-reading');
        $service->report($reading, AgentStatus::Reading, 'Reading BookingService.php', ['file' => 'app/Services/BookingService.php']);

        $paused = $service->start($this->task, $fe, 'implementation', 'map-paused');
        $service->pause($paused);

        $done = $service->start($this->task, $qa, 'qa', 'map-done');
        $service->complete($done);

        $this->actingAs($this->owner)->get('/war-room')->assertInertia(function (Assert $page) {
            $agents = collect($page->toArray()['props']['agents'])->keyBy('role');

            $this->assertSame('READING', $agents['BE']['status']);
            $this->assertSame('app/Services/BookingService.php', $agents['BE']['progress']['file']);
            $this->assertSame('PAUSED', $agents['FE']['status']);
            $this->assertSame('COMPLETED', $agents['QA']['status']);
            $this->assertSame('IDLE', $agents['PM']['status']);
            $this->assertSame('BE', $page->toArray()['props']['agents'][0]['role'], 'working agents sort to the top');
        });
    }

    public function test_presence_becomes_stalled_after_ninety_seconds_without_mutating_the_run(): void
    {
        $agent = $this->configuredAgent('BE');
        $run = app(AgentRunService::class)->start($this->task, $agent, 'implementation', 'stalled-presence');
        $run->forceFill(['heartbeat_at' => now()->subSeconds(91)])->save();

        $this->actingAs($this->owner)
            ->get('/war-room')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.active_runs', 1)
                ->where('metrics.stalled_runs', 1)
                ->where('agents.0.status', 'STALLED'));

        $this->assertSame(AgentRunStatus::Running, $run->fresh()->status);
    }

    public function test_a_foreign_project_cannot_be_selected_or_observed(): void
    {
        $foreign = Project::factory()->create(['slug' => 'foreign-project']);

        $this->actingAs($this->owner)
            ->get('/war-room?project=foreign-project')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.id', $this->project->id)
                ->missing('projects.1'));

        $this->assertNotSame($foreign->organization_id, $this->organization->id);
    }

    public function test_five_minute_heartbeat_failure_consumes_the_retry_and_blocks_at_the_limit(): void
    {
        $agent = $this->configuredAgent('BE');
        $agent->forceFill(['retry_limit' => 1])->save();
        $run = app(AgentRunService::class)->start($this->task, $agent, 'implementation', 'expired-run');
        $run->forceFill(['heartbeat_at' => now()->subMinutes(6)])->save();

        $this->artisan('eaj:recover-agent-runs')->expectsOutput('Recovered 1 stalled agent run(s).')->assertSuccessful();

        $this->assertSame(AgentRunStatus::Blocked, $run->fresh()->status);
        $this->assertSame(TaskStatus::Blocked, $this->task->fresh()->status);
        $this->assertDatabaseHas('agent_events', [
            'agent_run_id' => $run->id,
            'type' => 'agent.run.retry_limit_reached',
        ]);
    }

    public function test_private_realtime_channels_enforce_tenant_membership(): void
    {
        $viewer = User::factory()->memberOf($this->organization, OrganizationRole::Viewer)->create();
        $foreign = Organization::factory()->create();
        $foreignProject = Project::factory()->for($foreign)->create();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        Broadcast::purge('reverb');
        require base_path('routes/channels.php');

        $this->actingAs($viewer)->post('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-organization.'.$this->organization->id,
        ])->assertOk();

        $this->actingAs($viewer)->post('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-project.'.$this->project->id,
        ])->assertOk();

        $this->actingAs($viewer)->post('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-organization.'.$foreign->id,
        ])->assertForbidden();

        $this->actingAs($viewer)->post('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-project.'.$foreignProject->id,
        ])->assertForbidden();
    }

    private function configuredAgent(string $role): Agent
    {
        $agent = $this->organization->agents()
            ->whereHas('role', fn ($query) => $query->where('key', $role))
            ->firstOrFail();
        $route = $this->organization->modelRoutes()->where('agent_role_id', $agent->agent_role_id)->firstOrFail();
        $model = AiModel::forceCreate([
            'organization_id' => $this->organization->id,
            'provider' => 'test',
            'model_key' => 'test-'.$role,
            'display_name' => 'Test Model',
            'tier' => $route->tier,
        ]);
        $route->forceFill(['primary_model_id' => $model->id])->save();

        return $agent;
    }
}
