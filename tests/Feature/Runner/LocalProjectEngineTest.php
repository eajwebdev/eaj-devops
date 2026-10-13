<?php

namespace Tests\Feature\Runner;

use App\Actions\Workspaces\ManageWorkspaces;
use App\Enums\OrganizationRole;
use App\Enums\ProjectConnectionStatus;
use App\Enums\RunnerCommandStatus;
use App\Enums\RunnerCommandType;
use App\Enums\WorkspaceStatus;
use App\Models\Project;
use App\Models\ProjectIndexFile;
use App\Models\RunnerCommand;
use App\Models\User;
use App\Runner\Exceptions\RunnerUnavailableException;

/**
 * Plan Phase 2 — scan → profile → code index → ready; Git initialization;
 * task workspaces.
 */
class LocalProjectEngineTest extends RunnerTestCase
{
    public function test_creating_a_local_project_with_a_runner_queues_a_scan()
    {
        $this->actingAs($this->owner)->post('/projects', [
            'name' => 'Booking SaaS', 'key' => 'BOOK', 'connection_type' => 'local',
            'local_path' => 'C:\\laragon\\www\\booking-saas', 'local_runner_id' => $this->runner->id,
        ])->assertRedirect('/projects/booking-saas')->assertSessionHas('status', 'Project Booking SaaS created. Scan queued on the runner.');

        $project = Project::where('key', 'BOOK')->sole();
        $this->assertSame(ProjectConnectionStatus::Scanning, $project->connection->status);

        $claimed = $this->claimAsRunner();
        $this->assertSame('project.scan', $claimed['type']);
        $this->assertSame('C:\\laragon\\www\\booking-saas', $claimed['payload']['project_path']);
    }

    public function test_a_scan_result_becomes_the_project_profile_and_triggers_the_code_index()
    {
        $project = $this->localProject();
        $this->actingAs($this->owner)->post("/projects/{$project->slug}/scan")->assertSessionHas('status');
        $claimed = $this->claimAsRunner();

        $this->runnerApi('POST', "commands/{$claimed['id']}/progress", ['message' => 'Detected Laravel 10.48.4'])->assertOk();
        $this->assertSame('Detected Laravel 10.48.4', RunnerCommand::find($claimed['id'])->progress);

        $this->runnerApi('POST', "commands/{$claimed['id']}/complete", [
            'status' => 'succeeded',
            'result' => $this->scanResult(['warnings' => ['3 uncommitted change(s).']]),
        ])->assertOk();

        $connection = $project->connection->fresh();
        $this->assertSame('Laravel', $connection->detected_stack['backend']['framework']);
        $this->assertTrue($connection->git_enabled);
        $this->assertSame(['3 uncommitted change(s).'], $connection->warnings);
        $this->assertSame(ProjectConnectionStatus::Indexing, $connection->status);
        $this->assertSame(1, $project->snapshots()->count());
        $this->assertDatabaseHas('outbox_events', ['type' => 'project.scanned', 'project_id' => $project->id]);

        // Plan §12: the code index is built right after the profile.
        $index = $this->claimAsRunner();
        $this->assertSame('project.index', $index['type']);
    }

    public function test_secrets_a_runner_reports_are_stripped_server_side()
    {
        $project = $this->localProject();
        $this->actingAs($this->owner)->post("/projects/{$project->slug}/scan");
        $claimed = $this->claimAsRunner();

        // A misbehaving runner sends real values.
        $this->runnerApi('POST', "commands/{$claimed['id']}/complete", [
            'status' => 'succeeded',
            'result' => $this->scanResult(['stack' => ['envShape' => ['DB_CONNECTION' => 'mysql', 'DB_PASSWORD' => 'hunter2', 'STRIPE_SECRET' => 'sk_'.'live_51Habcdefghijklmnopqrstu'], 'name' => 'token sk-proj-abcdefghijklmnopqrstuvwxyz']]),
        ])->assertOk();

        $stack = $project->connection->fresh()->detected_stack;
        $this->assertSame(['DB_CONNECTION' => 'mysql', 'DB_PASSWORD' => '<set>', 'STRIPE_SECRET' => '<set>'], $stack['envShape']);
        $this->assertStringNotContainsString('sk-proj-abc', json_encode($stack));
        $this->assertStringNotContainsString('hunter2', json_encode(RunnerCommand::find($claimed['id'])->result));
    }

    public function test_the_code_index_is_streamed_swapped_atomically_and_searchable()
    {
        $project = $this->localProject();
        $files = fn (string $name) => [[
            'path' => 'app/Models/'.$name.'.php', 'language' => 'php', 'size' => 120, 'sha1' => str_repeat('a', 40),
            'symbols' => [['kind' => 'model', 'name' => $name, 'line' => 5], ['kind' => 'function', 'name' => 'customer', 'line' => 9]],
        ]];

        foreach (['Booking', 'Reservation'] as $build) {
            $this->actingAs($this->owner)->post("/projects/{$project->slug}/index");
            $claimed = $this->claimAsRunner();
            $this->runnerApi('POST', "commands/{$claimed['id']}/index-chunk", ['files' => $files($build)])->assertJsonPath('stored', 1);
            $this->runnerApi('POST', "commands/{$claimed['id']}/index-chunk", ['files' => $files($build)])->assertJsonPath('stored', 0); // re-sent chunk
            $this->runnerApi('POST', "commands/{$claimed['id']}/complete", ['status' => 'succeeded', 'result' => ['files' => 1, 'symbols' => 2, 'listed' => 1]])->assertOk();
        }

        $project->refresh();
        $this->assertSame(1, ProjectIndexFile::where('project_id', $project->id)->count(), 'the old build was replaced');
        $this->assertSame(['files' => 1, 'symbols' => 2, 'kinds' => ['function' => 1, 'model' => 1], 'listed' => 1], $project->connection->index_stats);
        $this->assertSame(ProjectConnectionStatus::Connected, $project->connection->status);

        $this->actingAs($this->owner)
            ->getJson("/api/projects/{$project->slug}/index/search?q=Reserv")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Reservation')
            ->assertJsonPath('data.0.path', 'app/Models/Reservation.php')
            ->assertJsonPath('data.0.line', 5);

        $this->actingAs($this->owner)->getJson("/api/projects/{$project->slug}/index/search?q=Booking")->assertJsonCount(0, 'data');
    }

    public function test_a_failed_index_build_never_replaces_a_good_index()
    {
        $project = $this->localProject();
        $project->forceFill(['index_build_id' => '01JGOODBUILD0000000000000'])->save();
        $good = ProjectIndexFile::forceCreate(['project_id' => $project->id, 'build_id' => '01JGOODBUILD0000000000000', 'path' => 'a.php', 'path_hash' => sha1('a.php'), 'language' => 'php', 'size' => 1]);

        $this->actingAs($this->owner)->post("/projects/{$project->slug}/index");
        $claimed = $this->claimAsRunner();
        $this->runnerApi('POST', "commands/{$claimed['id']}/index-chunk", ['files' => [['path' => 'b.php', 'language' => 'php', 'size' => 1, 'symbols' => []]]]);
        $this->runnerApi('POST', "commands/{$claimed['id']}/complete", ['status' => 'failed', 'error' => ['code' => 'COMMAND_FAILED', 'message' => 'disk error']]);

        $this->assertModelExists($good);
        $this->assertSame(1, ProjectIndexFile::where('project_id', $project->id)->count());
        $this->assertStringContainsString('Code index failed', $project->connection->fresh()->last_error);
    }

    public function test_initializing_git_needs_explicit_approval_and_update_permission()
    {
        $project = $this->localProject();
        $project->connection->forceFill(['git_enabled' => false])->save();
        $developer = User::factory()->memberOf($this->organization, OrganizationRole::Developer)->create();

        $this->actingAs($developer)->post("/projects/{$project->slug}/git-init", ['confirm' => true])->assertForbidden();
        $this->actingAs($this->owner)->post("/projects/{$project->slug}/git-init")->assertSessionHasErrors('confirm');
        $this->assertSame(0, RunnerCommand::count());

        $this->actingAs($this->owner)->post("/projects/{$project->slug}/git-init", ['confirm' => '1'])->assertSessionHas('status');
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.git_init.approved', 'user_id' => $this->owner->id]);

        $claimed = $this->claimAsRunner();
        $this->assertSame('git.init', $claimed['type']);
        $this->runnerApi('POST', "commands/{$claimed['id']}/complete", ['status' => 'succeeded', 'result' => ['head' => str_repeat('b', 40), 'branch' => 'main', 'files_committed' => 120, 'gitignore_created' => true, 'secrets_excluded' => ['.env']]]);

        $this->assertTrue($project->connection->fresh()->git_enabled);
        $this->assertSame('project.scan', $this->claimAsRunner()['type'], 'the project is re-scanned after Git init');
    }

    public function test_only_one_pending_scan_per_project()
    {
        $project = $this->localProject();

        $this->actingAs($this->owner)->post("/projects/{$project->slug}/scan");
        $this->actingAs($this->owner)->post("/projects/{$project->slug}/scan");

        $this->assertSame(1, RunnerCommand::where('type', RunnerCommandType::ProjectScan)->count());
    }

    public function test_scanning_without_a_runner_explains_what_to_do()
    {
        $project = Project::factory()->for($this->organization)->create();

        $this->actingAs($this->owner)->post("/projects/{$project->slug}/scan")->assertSessionHasErrors(['runner' => 'Assign an active EAJ Local Runner to this project first.']);
    }

    public function test_workspace_lifecycle_runs_through_the_runner()
    {
        $project = $this->localProject();
        $project->connection->forceFill(['git_enabled' => true])->save();
        $workspaces = app(ManageWorkspaces::class);

        $workspace = $workspaces->create($project->fresh('connection.runner'), 'BOOK-101', 'Monthly revenue dashboard', $this->owner);
        $this->assertSame('ai/BOOK-101-monthly-revenue-dashboard', $workspace->branch);
        $this->assertSame(WorkspaceStatus::Creating, $workspace->status);

        $claimed = $this->claimAsRunner();
        $this->assertSame('workspace.create', $claimed['type']);
        $this->assertSame(['workspace_id' => $workspace->id, 'project_path' => $project->connection->local_path, 'task_key' => 'BOOK-101', 'slug' => 'monthly-revenue-dashboard', 'base_branch' => null], $claimed['payload']);

        $this->runnerApi('POST', "commands/{$claimed['id']}/complete", ['status' => 'succeeded', 'result' => [
            'path' => 'C:\\EAJ\\workspaces\\booking-saas\\BOOK-101', 'branch' => 'ai/BOOK-101-monthly-revenue-dashboard', 'base_branch' => 'main', 'base_sha' => str_repeat('c', 40), 'safety_stash' => null,
        ]]);
        $workspace->refresh();
        $this->assertSame(WorkspaceStatus::Ready, $workspace->status);
        $this->assertDatabaseHas('outbox_events', ['type' => 'workspace.created', 'project_id' => $project->id]);

        // The same task never gets a second workspace.
        $this->assertTrue($workspaces->create($project, 'BOOK-101', 'Again', $this->owner)->is($workspace));

        $workspaces->checkpoint($workspace, 1);
        $cp = $this->claimAsRunner();
        $this->runnerApi('POST', "commands/{$cp['id']}/complete", ['status' => 'succeeded', 'result' => ['sha' => str_repeat('d', 40), 'changed' => true, 'files' => ['app/Revenue.php']]]);
        $this->assertSame(str_repeat('d', 40), $workspace->fresh()->last_checkpoint_sha);

        $workspaces->remove($workspace->fresh(), $this->owner);
        $rm = $this->claimAsRunner();
        $this->assertFalse($rm['payload']['force']);
        $this->runnerApi('POST', "commands/{$rm['id']}/complete", ['status' => 'succeeded', 'result' => ['removed' => true]]);
        $this->assertSame(WorkspaceStatus::Removed, $workspace->fresh()->status);
    }

    public function test_workspaces_require_git_and_a_valid_task_key()
    {
        $project = $this->localProject();
        $project->connection->forceFill(['git_enabled' => false])->save();

        $this->expectException(RunnerUnavailableException::class);
        app(ManageWorkspaces::class)->create($project->fresh('connection.runner'), 'BOOK-1', 'x', $this->owner);
    }

    public function test_a_failed_workspace_creation_is_visible_with_the_reason()
    {
        $project = $this->localProject();
        $project->connection->forceFill(['git_enabled' => true])->save();
        $workspace = app(ManageWorkspaces::class)->create($project->fresh('connection.runner'), 'BOOK-7', 'x', $this->owner);

        $claimed = $this->claimAsRunner();
        $this->runnerApi('POST', "commands/{$claimed['id']}/complete", ['status' => 'failed', 'error' => ['code' => 'LOW_DISK_SPACE', 'message' => 'Not enough free disk space: 100 MB free, 900 MB needed.']]);

        $workspace->refresh();
        $this->assertSame(WorkspaceStatus::Failed, $workspace->status);
        $this->assertStringContainsString('Not enough free disk space', $workspace->error);
        $this->assertSame(RunnerCommandStatus::Failed, RunnerCommand::find($claimed['id'])->status);
    }
}
