<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CommandCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/command-center')->assertRedirect('/login');
    }

    public function test_root_redirects_to_the_command_center()
    {
        $this->get('/')->assertRedirect('/command-center');
    }

    public function test_authenticated_users_see_real_metrics_for_their_organization()
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->memberOf($organization)->create();
        Project::factory()->count(2)->for($organization)->create();
        Project::factory()->create(); // another tenant — must not be counted

        $this->actingAs($user)
            ->get('/command-center')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('command-center')
                ->where('metrics.active_projects', 2)
                ->where('metrics.open_tasks', 0)
                ->has('projects', 2)
                ->has('health.checks')
                ->where('organization.id', $organization->id)
                ->where('organization.role', OrganizationRole::Owner->label())
            );
    }

    public function test_a_user_without_an_organization_gets_a_personal_one()
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace']);

        $this->actingAs($user)->get('/command-center')->assertOk();

        $user->refresh();
        $this->assertNotNull($user->current_organization_id);
        $this->assertSame(OrganizationRole::Owner, $user->roleIn($user->currentOrganization));
        $this->assertSame("Ada Lovelace's Organization", $user->currentOrganization->name);
    }

    public function test_health_endpoint_reports_component_status_without_tenant_data()
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonStructure(['status', 'checks' => ['database', 'cache', 'redis', 'queue', 'outbox', 'realtime', 'runners', 'operations'], 'time'])
            ->assertJsonPath('checks.database', 'ok');
    }

    public function test_responses_carry_a_request_id()
    {
        $this->get('/health')->assertHeader('X-Request-Id');

        $this->withHeader('X-Request-Id', 'trace-12345678')
            ->get('/health')
            ->assertHeader('X-Request-Id', 'trace-12345678');
    }
}
