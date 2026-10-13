<?php

namespace Tests\Feature\Reliability;

use App\Enums\OrganizationRole;
use App\Enums\WorkflowStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Workflow;
use App\Reliability\SystemHalt;
use App\Workflow\Exceptions\OperationsHaltedException;
use App\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ScriptedStepHandler;
use Tests\TestCase;

/**
 * Plan §57 — STOP ALL AI OPERATIONS.
 */
class EmergencyStopTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        ScriptedStepHandler::reset();
        config(['eaj.step_handlers' => ['plan' => ScriptedStepHandler::class, 'build' => ScriptedStepHandler::class]]);
        Queue::fake();

        $this->organization = Organization::factory()->create();
        $this->project = Project::factory()->for($this->organization)->create();
    }

    private function user(OrganizationRole $role, ?Organization $organization = null): User
    {
        return User::factory()->memberOf($organization ?? $this->organization, $role)->create();
    }

    private function startWorkflow(?Project $project = null): Workflow
    {
        $project ??= $this->project;

        return app(WorkflowEngine::class)->start($project, 'test', [['name' => 'plan'], ['name' => 'build']], $project->organization, $project);
    }

    public function test_developer_can_stop_operations_which_pauses_active_workflows()
    {
        $workflow = $this->startWorkflow();
        $otherTenant = $this->startWorkflow(Project::factory()->create());

        $this->actingAs($this->user(OrganizationRole::Developer))
            ->post('/operations/halt', ['reason' => 'Unexpected file changes'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(WorkflowStatus::Paused, $workflow->refresh()->status);
        $this->assertSame(WorkflowStatus::Running, $otherTenant->refresh()->status);
        $this->assertTrue(app(SystemHalt::class)->isHalted($this->organization->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'operations.halted', 'organization_id' => $this->organization->id]);
        $this->assertDatabaseHas('outbox_events', ['type' => 'operations.halted', 'organization_id' => $this->organization->id]);
    }

    public function test_no_step_runs_and_no_new_work_starts_while_halted()
    {
        $workflow = $this->startWorkflow();
        $step = $workflow->steps()->first();
        app(SystemHalt::class)->set($this->organization, $this->user(OrganizationRole::Owner), 'test', []);

        app(WorkflowEngine::class)->runStep($step->id);
        $this->assertSame([], ScriptedStepHandler::$runs);

        $this->expectException(OperationsHaltedException::class);
        $this->startWorkflow();
    }

    public function test_only_owners_can_resume_and_resume_restarts_exactly_the_paused_work()
    {
        $workflow = $this->startWorkflow();
        $developer = $this->user(OrganizationRole::Developer);
        $owner = $this->user(OrganizationRole::Owner);

        $this->actingAs($developer)->post('/operations/halt', ['reason' => 'Stop'])->assertRedirect();
        $this->actingAs($developer)->post('/operations/resume')->assertForbidden();

        $this->actingAs($owner)
            ->getJson('/operations/resume-preview')
            ->assertOk()
            ->assertJsonCount(1, 'workflows')
            ->assertJsonPath('workflows.0.id', $workflow->id);

        $this->actingAs($owner)->post('/operations/resume')->assertRedirect();

        $this->assertSame(WorkflowStatus::Running, $workflow->refresh()->status);
        $this->assertFalse(app(SystemHalt::class)->isHalted($this->organization->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'operations.resumed', 'user_id' => $owner->id]);
    }

    public function test_viewers_cannot_stop_operations()
    {
        $this->actingAs($this->user(OrganizationRole::Viewer))
            ->post('/operations/halt', ['reason' => 'Stop'])
            ->assertForbidden();
    }

    public function test_global_stop_requires_super_admin_and_stops_every_organization()
    {
        $owner = $this->user(OrganizationRole::Owner);
        $this->actingAs($owner)->post('/operations/halt', ['reason' => 'Stop', 'scope' => 'global'])->assertForbidden();

        $admin = User::factory()->superAdmin()->create();
        $elsewhere = $this->startWorkflow(Project::factory()->create());

        $this->actingAs($admin)->post('/operations/halt', ['reason' => 'Provider incident', 'scope' => 'global'])->assertRedirect();

        $this->assertTrue(app(SystemHalt::class)->isHalted(Organization::factory()->create()->id));
        $this->assertSame(WorkflowStatus::Paused, $elsewhere->refresh()->status);
    }

    public function test_work_stays_paused_while_another_stop_is_in_force()
    {
        $workflow = $this->startWorkflow();
        $admin = User::factory()->superAdmin()->create();
        $owner = $this->user(OrganizationRole::Owner);

        $this->actingAs($admin)->post('/operations/halt', ['reason' => 'Global', 'scope' => 'global']);
        $this->actingAs($owner)->post('/operations/halt', ['reason' => 'Org']);

        // Lifting the global stop must not resume an organization that is still stopped.
        $this->actingAs($admin)->post('/operations/resume', ['scope' => 'global']);
        $this->assertSame(WorkflowStatus::Paused, $workflow->refresh()->status);

        $this->actingAs($owner)->post('/operations/resume');
        $this->assertSame(WorkflowStatus::Running, $workflow->refresh()->status);
    }

    public function test_halt_state_is_shared_with_the_ui()
    {
        $owner = $this->user(OrganizationRole::Owner);
        $this->actingAs($owner)->post('/operations/halt', ['reason' => 'Checking a bad diff']);

        $this->actingAs($owner)
            ->get('/command-center')
            ->assertInertia(fn ($page) => $page
                ->where('operations.halted', true)
                ->where('operations.scope', 'organization')
                ->where('operations.reason', 'Checking a bad diff')
                ->where('health.checks.7.key', 'operations')
                ->where('health.checks.7.status', 'degraded'));
    }
}
