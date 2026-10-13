<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Enums\ProjectConnectionStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private function member(OrganizationRole $role = OrganizationRole::Owner, ?Organization $organization = null): User
    {
        return User::factory()->memberOf($organization ?? Organization::factory()->create(), $role)->create();
    }

    private function validPayload(array $overrides = []): array
    {
        return [
            'name' => 'Booking SaaS',
            'key' => 'book',
            'description' => 'Reservations platform',
            'connection_type' => 'local',
            'local_path' => 'C:\\laragon\\www\\booking-saas\\',
            ...$overrides,
        ];
    }

    public function test_owner_can_register_a_local_project()
    {
        $user = $this->member();

        $response = $this->actingAs($user)->post('/projects', $this->validPayload());

        $project = Project::firstOrFail();
        $response->assertRedirect("/projects/{$project->slug}");

        $this->assertSame('BOOK', $project->key);
        $this->assertSame('booking-saas', $project->slug);
        $this->assertSame($user->current_organization_id, $project->organization_id);
        $this->assertSame('C:\\laragon\\www\\booking-saas', $project->connection->local_path);
        $this->assertSame(ProjectConnectionStatus::PendingScan, $project->connection->status);

        $this->assertDatabaseHas('outbox_events', ['project_id' => $project->id, 'type' => 'project.created', 'sequence' => 1]);
        $this->assertDatabaseHas('audit_logs', ['project_id' => $project->id, 'action' => 'project.created', 'user_id' => $user->id]);
    }

    public function test_organization_id_cannot_be_injected_through_the_request()
    {
        $user = $this->member();
        $victim = Organization::factory()->create();

        $this->actingAs($user)->post('/projects', $this->validPayload(['organization_id' => $victim->id]));

        $this->assertSame($user->current_organization_id, Project::firstOrFail()->organization_id);
    }

    public function test_project_input_is_validated()
    {
        $user = $this->member();
        Project::factory()->for($user->currentOrganization)->create(['key' => 'BOOK']);

        $this->actingAs($user)
            ->post('/projects', $this->validPayload([
                'key' => 'BOOK',
                'local_path' => 'relative\\path',
            ]))
            ->assertSessionHasErrors(['key', 'local_path']);

        $this->actingAs($user)
            ->post('/projects', $this->validPayload(['key' => 'NEW', 'local_path' => 'C:\\laragon\\www\\..\\..\\Windows']))
            ->assertSessionHasErrors('local_path');

        // GitHub import exists (Phase 10): it needs a repository chosen, not a folder path.
        $this->actingAs($user)
            ->post('/projects', $this->validPayload(['key' => 'GH', 'connection_type' => 'github']))
            ->assertSessionHasErrors('github_repository');

        // Types whose engine does not exist yet still cannot be selected.
        $this->actingAs($user)
            ->post('/projects', $this->validPayload(['key' => 'NEW', 'connection_type' => 'new']))
            ->assertSessionHasErrors('connection_type');
    }

    public function test_viewers_and_developers_cannot_create_projects()
    {
        foreach ([OrganizationRole::Viewer, OrganizationRole::Developer] as $role) {
            $this->actingAs($this->member($role))
                ->post('/projects', $this->validPayload())
                ->assertForbidden();
        }

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_project_index_only_lists_the_current_organizations_projects()
    {
        $user = $this->member(OrganizationRole::Viewer);
        Project::factory()->for($user->currentOrganization)->create(['name' => 'Mine']);
        Project::factory()->create(['name' => 'Someone Else']);

        $this->actingAs($user)
            ->get('/projects')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/index')
                ->has('projects', 1)
                ->where('projects.0.name', 'Mine')
                ->where('can.create', false)
            );
    }

    public function test_another_organizations_project_is_not_found()
    {
        $user = $this->member();
        $other = Project::factory()->create();

        $this->actingAs($user)->get("/projects/{$other->slug}")->assertNotFound();
        $this->actingAs($user)->getJson("/api/projects/{$other->slug}/events")->assertNotFound();
        $this->actingAs($user)->delete("/projects/{$other->slug}", ['confirm_key' => $other->key])->assertNotFound();

        $this->assertModelExists($other);
    }

    public function test_same_slug_in_two_organizations_resolves_to_the_current_one()
    {
        $user = $this->member();
        $mine = Project::factory()->for($user->currentOrganization)->create(['slug' => 'shop']);
        Project::factory()->create(['slug' => 'shop']);

        $this->actingAs($user)
            ->get('/projects/shop')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('project.id', $mine->id));
    }

    public function test_deleting_requires_permission_and_typed_confirmation()
    {
        $owner = $this->member();
        $project = Project::factory()->for($owner->currentOrganization)->create(['key' => 'POS']);
        $developer = $this->member(OrganizationRole::Developer, $owner->currentOrganization);

        $this->actingAs($developer)->delete("/projects/{$project->slug}", ['confirm_key' => 'POS'])->assertForbidden();

        $this->actingAs($owner)->delete("/projects/{$project->slug}", ['confirm_key' => 'WRONG'])->assertSessionHasErrors('confirm_key');
        $this->assertModelExists($project);

        $this->actingAs($owner)->delete("/projects/{$project->slug}", ['confirm_key' => 'POS'])->assertRedirect('/projects');
        $this->assertModelMissing($project);

        $this->assertTrue(AuditLog::where('action', 'project.deleted')->exists());
        $this->assertTrue(OutboxEvent::where('type', 'project.deleted')->where('organization_id', $owner->current_organization_id)->exists());
    }
}
