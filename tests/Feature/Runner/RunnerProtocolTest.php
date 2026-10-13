<?php

namespace Tests\Feature\Runner;

use App\Enums\OrganizationRole;
use App\Enums\ProjectConnectionStatus;
use App\Enums\RunnerCommandStatus;
use App\Enums\RunnerCommandType;
use App\Models\LocalRunner;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RunnerCommand;
use App\Models\User;
use App\Reliability\SystemHalt;
use App\Runner\RunnerGateway;
use App\Runner\RunnerPairing;

/**
 * Plan §89.2 — the Studio ⇄ Local Runner protocol.
 */
class RunnerProtocolTest extends RunnerTestCase
{
    public function test_owner_creates_a_single_use_pairing_code_and_a_runner_redeems_it()
    {
        $page = $this->actingAs($this->owner)->post('/settings/runners/pairing-codes')->assertRedirect();
        $code = $page->getSession()->get('pairing')['code'];
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);

        $this->postJson('/api/runner/v1/pair', ['code' => strtolower($code), 'name' => 'Laragon PC', 'platform' => 'win32'])
            ->assertCreated()
            ->assertJsonStructure(['runner_id', 'token', 'signing_secret', 'organization']);

        $this->postJson('/api/runner/v1/pair', ['code' => $code, 'name' => 'Again'])->assertUnprocessable();

        $runner = LocalRunner::where('name', 'Laragon PC')->sole();
        $this->assertSame($this->organization->id, $runner->organization_id);
        $this->assertSame(64, strlen($runner->token_hash), 'only a hash of the token is stored');
        $this->assertDatabaseHas('outbox_events', ['type' => 'runner.paired', 'organization_id' => $this->organization->id]);
    }

    public function test_expired_codes_and_viewers_cannot_pair()
    {
        $viewer = User::factory()->memberOf($this->organization, OrganizationRole::Viewer)->create();
        $this->actingAs($viewer)->post('/settings/runners/pairing-codes')->assertForbidden();

        $code = app(RunnerPairing::class)->createCode($this->organization, $this->owner)['code'];
        $this->travel(11)->minutes();

        $this->postJson('/api/runner/v1/pair', ['code' => $code, 'name' => 'Late'])->assertUnprocessable();
    }

    public function test_runner_api_requires_a_valid_unrevoked_token()
    {
        $this->postJson('/api/runner/v1/heartbeat')->assertUnauthorized();
        $this->runnerApi('POST', 'heartbeat', token: 'not-a-token')->assertUnauthorized();

        $this->runnerApi('POST', 'heartbeat', ['version' => '0.1.0', 'allowed_roots' => ['D:\\Projects']])
            ->assertOk()
            ->assertJsonPath('halted', false);
        $this->assertSame(['D:\\Projects'], $this->runner->fresh()->allowed_roots);

        $this->actingAs($this->owner)->delete("/settings/runners/{$this->runner->id}")->assertRedirect();
        $this->runnerApi('POST', 'heartbeat')->assertUnauthorized();
    }

    public function test_claimed_commands_are_signed_leased_and_handed_out_once()
    {
        $project = $this->localProject();
        app(RunnerGateway::class)->dispatch($this->runner, RunnerCommandType::ProjectScan, ['project_path' => 'C:\\laragon\\www\\shop'], $project);

        $claimed = $this->claimAsRunner();
        $this->assertSame('project.scan', $claimed['type']);
        $this->assertSame(['project_path' => 'C:\\laragon\\www\\shop'], $claimed['payload']);

        $command = RunnerCommand::find($claimed['id']);
        $this->assertSame(RunnerCommandStatus::Claimed, $command->status);
        $this->assertTrue($command->lease_expires_at->isFuture());

        $this->runnerApi('POST', 'commands/claim')->assertJsonPath('command', null);
    }

    public function test_nothing_is_handed_out_while_operations_are_stopped()
    {
        $project = $this->localProject();
        app(RunnerGateway::class)->dispatch($this->runner, RunnerCommandType::ProjectScan, ['project_path' => 'C:\\x'], $project);
        app(SystemHalt::class)->set($this->organization, $this->owner, 'test', []);

        $this->runnerApi('POST', 'commands/claim')->assertJsonPath('command', null)->assertJsonPath('halted', true);
        $this->runnerApi('POST', 'heartbeat')->assertJsonPath('halted', true);
    }

    public function test_a_runner_cannot_touch_another_runners_commands()
    {
        $otherOrg = Organization::factory()->create();
        [$otherRunner] = $this->pairRunner($otherOrg, User::factory()->memberOf($otherOrg)->create(), 'Other');
        $foreign = app(RunnerGateway::class)->dispatch($otherRunner, RunnerCommandType::ProjectScan, ['project_path' => 'C:\\x']);

        $this->runnerApi('POST', "commands/{$foreign->id}/complete", ['status' => 'succeeded', 'result' => []])->assertNotFound();
        $this->runnerApi('POST', "commands/{$foreign->id}/progress", ['message' => 'x'])->assertNotFound();
        $this->assertSame(RunnerCommandStatus::Queued, $foreign->fresh()->status);
    }

    public function test_results_are_idempotent()
    {
        $project = $this->localProject();
        app(RunnerGateway::class)->dispatch($this->runner, RunnerCommandType::ProjectScan, ['project_path' => $project->connection->local_path], $project);
        $claimed = $this->claimAsRunner();

        $this->runnerApi('POST', "commands/{$claimed['id']}/complete", ['status' => 'succeeded', 'result' => $this->scanResult()])->assertOk();
        $this->runnerApi('POST', "commands/{$claimed['id']}/complete", ['status' => 'succeeded', 'result' => $this->scanResult()])->assertOk();

        $this->assertSame(1, $project->snapshots()->count());
        $this->assertSame(1, RunnerCommand::where('type', RunnerCommandType::ProjectIndex)->count(), 'one index build queued, not two');
    }

    public function test_an_expired_lease_is_offered_again_then_fails_after_max_attempts()
    {
        $project = $this->localProject();
        $command = app(RunnerGateway::class)->dispatch($this->runner, RunnerCommandType::ProjectScan, ['project_path' => 'C:\\x'], $project);
        $project->connection->forceFill(['status' => ProjectConnectionStatus::Scanning])->save();

        foreach (range(1, 3) as $attempt) {
            $this->claimAsRunner();
            $this->travel(10)->minutes();
            app(RunnerGateway::class)->recover();
        }

        $command->refresh();
        $this->assertSame(RunnerCommandStatus::Failed, $command->status);
        $this->assertSame('LEASE_EXPIRED', $command->error_code);
        $this->assertSame(3, $command->attempts);
        $this->assertSame(ProjectConnectionStatus::Error, $project->connection->fresh()->status);
    }

    public function test_unclaimed_commands_expire_with_a_clear_next_action()
    {
        $project = $this->localProject();
        $command = app(RunnerGateway::class)->dispatch($this->runner, RunnerCommandType::ProjectScan, ['project_path' => 'C:\\x'], $project);

        $this->travel(25)->hours();
        $result = app(RunnerGateway::class)->recover();

        $this->assertSame(1, $result['expired']);
        $this->assertSame(RunnerCommandStatus::Expired, $command->fresh()->status);
        $this->assertStringContainsString('Start the runner', $project->connection->fresh()->last_error);
    }

    public function test_revoking_a_runner_cancels_its_pending_work()
    {
        $project = $this->localProject();
        $command = app(RunnerGateway::class)->dispatch($this->runner, RunnerCommandType::ProjectScan, ['project_path' => 'C:\\x'], $project);

        $this->actingAs($this->owner)->delete("/settings/runners/{$this->runner->id}")->assertRedirect();

        $this->assertSame(RunnerCommandStatus::Cancelled, $command->fresh()->status);
        $this->assertNotNull($this->runner->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'runner.revoked']);
    }

    public function test_runners_of_other_organizations_are_invisible_in_settings()
    {
        $otherOrg = Organization::factory()->create();
        [$otherRunner] = $this->pairRunner($otherOrg, User::factory()->memberOf($otherOrg)->create(), 'Other');

        $this->actingAs($this->owner)->delete("/settings/runners/{$otherRunner->id}")->assertNotFound();
        $this->actingAs($this->owner)->get('/settings/runners')->assertOk()->assertInertia(fn ($page) => $page
            ->component('settings/runners')
            ->has('runners', 1)
            ->where('runners.0.name', 'Dev PC')
            ->where('runners.0.status', 'online'));

        $this->assertNull($otherRunner->fresh()->revoked_at);
    }

    public function test_runner_status_follows_heartbeats()
    {
        $this->assertTrue($this->runner->fresh()->isOnline());

        $this->travel(2)->minutes();
        $this->assertSame('stale', $this->runner->fresh()->status()->value);

        $this->travel(5)->minutes();
        $this->assertSame('offline', $this->runner->fresh()->status()->value);

        $this->runnerApi('POST', 'heartbeat')->assertOk();
        $this->assertTrue($this->runner->fresh()->isOnline());
        $this->assertDatabaseHas('outbox_events', ['type' => 'runner.connected']);
    }

    public function test_projects_can_only_use_runners_of_their_own_organization()
    {
        $otherOrg = Organization::factory()->create();
        [$otherRunner] = $this->pairRunner($otherOrg, User::factory()->memberOf($otherOrg)->create(), 'Other');

        $this->actingAs($this->owner)->post('/projects', [
            'name' => 'Shop', 'key' => 'SHOP', 'connection_type' => 'local', 'local_path' => 'C:\\laragon\\www\\shop', 'local_runner_id' => $otherRunner->id,
        ])->assertSessionHasErrors('local_runner_id');

        $project = Project::factory()->for($this->organization)->create();
        $this->actingAs($this->owner)->put("/projects/{$project->slug}/runner", ['local_runner_id' => $otherRunner->id])->assertSessionHasErrors('local_runner_id');
    }
}
