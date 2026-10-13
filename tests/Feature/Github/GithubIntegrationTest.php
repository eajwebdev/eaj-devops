<?php

namespace Tests\Feature\Github;

use App\Delivery\DeliveryWorkflow;
use App\Delivery\Steps\ReviewDecisionStep;
use App\Enums\ProjectConnectionType;
use App\Enums\RunnerCommandType;
use App\Enums\StepStatus;
use App\Enums\TaskStatus;
use App\Enums\WorkflowStatus;
use App\Enums\WorkspaceStatus;
use App\Models\GithubConnection;
use App\Models\GithubRepository;
use App\Models\Project;
use App\Models\RepositoryWorkspace;
use App\Models\Task;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Runner\RunnerGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Runner\RunnerTestCase;

class GithubIntegrationTest extends RunnerTestCase
{
    public function test_oauth_state_is_validated_and_tokens_are_encrypted(): void
    {
        config()->set('services.github.client_id', 'client');
        config()->set('services.github.client_secret', 'secret');
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_top_secret'], 200, ['X-OAuth-Scopes' => 'repo, read:user']),
            'api.github.com/user' => Http::response(['id' => 42, 'login' => 'octocat', 'avatar_url' => 'https://example.test/avatar.png']),
        ]);

        $connect = $this->actingAs($this->owner)->get('/github/connect')->assertRedirect();
        parse_str((string) parse_url($connect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get('/github/callback?'.http_build_query(['code' => 'code', 'state' => $query['state']]))
            ->assertRedirect(route('repositories.index'));

        $connection = GithubConnection::sole();
        $this->assertSame('octocat', $connection->login);
        $this->assertSame('gho_top_secret', $connection->access_token);
        $this->assertStringNotContainsString('gho_top_secret', (string) $connection->getRawOriginal('access_token'));
    }

    public function test_reviewed_branch_is_published_without_a_github_token_then_a_pull_request_is_created(): void
    {
        [$project, $task, $workspace, $repository, $sha] = $this->releaseFixture(TaskStatus::ReleaseReady);

        $this->actingAs($this->owner)->post("/projects/{$project->slug}/tasks/{$task->number}/github")
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        $command = $project->runnerCommands()->where('type', RunnerCommandType::WorkspacePush)->sole();
        $this->assertSame($repository->full_name, $command->payload['repository_full_name']);
        $this->assertArrayNotHasKey('token', $command->payload);

        app(RunnerGateway::class)->complete($command, true, ['sha' => $sha, 'branch' => $workspace->branch]);
        $this->assertSame($sha, $workspace->fresh()->published_sha);

        Http::fake(function (Request $request) use ($workspace, $sha) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/pulls')) {
                return Http::response($this->pullPayload($workspace->branch, $sha), 201);
            }
            if (str_contains($request->url(), '/pulls/17/reviews')) {
                return Http::response([]);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $this->post("/projects/{$project->slug}/tasks/{$task->number}/github")
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Pull request #17 is ready.');
        $this->assertDatabaseHas('github_pull_requests', ['task_id' => $task->id, 'github_number' => 17, 'head_sha' => $sha]);
    }

    public function test_merge_requires_the_reviewed_sha_and_creates_a_backup_ref(): void
    {
        [$project, $task, $workspace, $repository, $sha] = $this->releaseFixture(TaskStatus::Done);
        $workspace->forceFill(['published_sha' => $sha, 'published_at' => now()])->save();
        $pull = $repository->pullRequests()->create([
            'organization_id' => $this->organization->id, 'project_id' => $project->id, 'task_id' => $task->id,
            'github_number' => 17, 'title' => $task->title, 'state' => 'open', 'draft' => false,
            'head_branch' => $workspace->branch, 'base_branch' => 'main', 'head_sha' => $sha,
            'base_sha' => $workspace->base_sha, 'html_url' => 'https://github.com/acme/shop/pull/17',
        ]);

        Http::fake(function (Request $request) use ($workspace, $sha) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/pulls/17')) {
                return Http::response($this->pullPayload($workspace->branch, $sha));
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/pulls/17/reviews')) {
                return Http::response([['id' => 9, 'user' => ['login' => 'reviewer'], 'state' => 'APPROVED', 'commit_id' => $sha, 'submitted_at' => now()->toIso8601String()]]);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/git/refs')) {
                return Http::response(['ref' => 'refs/tags/eaj-backup'], 201);
            }
            if ($request->method() === 'PUT' && str_ends_with($request->url(), '/pulls/17/merge')) {
                return Http::response(['merged' => true, 'sha' => str_repeat('f', 40)]);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $this->actingAs($this->owner)->post("/projects/{$project->slug}/tasks/{$task->number}/github/{$pull->id}/merge", ['method' => 'squash'])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($pull->fresh()->merged_at);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/git/refs')
            && str_starts_with((string) $request['ref'], 'refs/tags/eaj-backup/'.$task->key().'/') && $request['sha'] === $workspace->base_sha);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/pulls/17/merge') && $request['sha'] === $sha);
    }

    public function test_a_github_repository_is_imported_as_a_project_without_a_local_path(): void
    {
        $this->githubConnection();
        Http::fake([
            'api.github.com/repos/acme/shop' => Http::response([
                'id' => 99, 'full_name' => 'acme/shop', 'name' => 'shop', 'owner' => ['login' => 'acme'],
                'default_branch' => 'main', 'clone_url' => 'https://github.com/acme/shop.git',
                'html_url' => 'https://github.com/acme/shop', 'private' => true, 'archived' => false,
                'permissions' => ['push' => true],
            ]),
        ]);

        $this->actingAs($this->owner)->post('/projects', [
            'name' => 'Shop', 'key' => 'SHOP', 'connection_type' => 'github', 'github_repository' => 'acme/shop',
        ])->assertSessionHasNoErrors();

        $project = Project::sole();
        $this->assertSame(ProjectConnectionType::GitHub, $project->connection->type);
        $this->assertNull($project->connection->local_path);
        $this->assertTrue($project->connection->git_enabled);
        $this->assertDatabaseHas('github_repositories', [
            'project_id' => $project->id, 'full_name' => 'acme/shop', 'default_branch' => 'main',
        ]);
    }

    public function test_the_webhook_verifies_its_signature_syncs_the_pull_and_ignores_replays(): void
    {
        config()->set('services.github.webhook_secret', 'whsec');
        [, , $workspace, $repository] = $this->releaseFixture(TaskStatus::ReleaseReady);

        $body = json_encode([
            'action' => 'opened',
            'repository' => ['full_name' => $repository->full_name],
            'pull_request' => array_merge($this->pullPayload($workspace->branch, str_repeat('c', 40)), ['number' => 12]),
        ]);
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GitHub_Event' => 'pull_request',
            'HTTP_X_GitHub_Delivery' => 'delivery-1',
            'HTTP_X_Hub_Signature_256' => 'sha256='.hash_hmac('sha256', $body, 'whsec'),
        ];

        $this->call('POST', '/api/github/webhook', [], [], [], $headers, $body)->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('github_pull_requests', ['github_repository_id' => $repository->id, 'github_number' => 12, 'head_sha' => str_repeat('c', 40)]);
        $this->assertDatabaseHas('github_webhook_deliveries', ['delivery_id' => 'delivery-1', 'pull_request_number' => 12]);

        // GitHub retries deliveries; the replay is acknowledged but not processed twice.
        $this->call('POST', '/api/github/webhook', [], [], [], $headers, $body)->assertOk()->assertJson(['duplicate' => true]);
        $this->assertDatabaseCount('github_webhook_deliveries', 1);
    }

    public function test_the_webhook_rejects_an_unverified_signature(): void
    {
        config()->set('services.github.webhook_secret', 'whsec');
        $body = json_encode(['repository' => ['full_name' => 'acme/shop']]);

        $this->call('POST', '/api/github/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GitHub_Event' => 'pull_request',
            'HTTP_X_GitHub_Delivery' => 'delivery-x',
            'HTTP_X_Hub_Signature_256' => 'sha256='.str_repeat('0', 64),
        ], $body)->assertStatus(401);
        $this->assertDatabaseCount('github_webhook_deliveries', 0);
    }

    private function githubConnection(): GithubConnection
    {
        return GithubConnection::create([
            'organization_id' => $this->organization->id, 'connected_by' => $this->owner->id,
            'github_user_id' => 42, 'login' => 'octocat', 'access_token' => 'secret-token', 'scopes' => ['repo'],
        ]);
    }

    /** @return array{0: mixed, 1: Task, 2: RepositoryWorkspace, 3: GithubRepository, 4: string} */
    private function releaseFixture(TaskStatus $status): array
    {
        $project = $this->localProject(attributes: ['key' => 'SHOP', 'slug' => 'shop']);
        $project->connection->forceFill(['git_enabled' => true, 'default_branch' => 'main'])->save();
        $task = Task::factory()->for($project)->status($status)->create(['title' => 'Ship reviewed work', 'acceptance_criteria' => ['It works']]);
        $sha = str_repeat('b', 40);
        $workspace = RepositoryWorkspace::forceCreate([
            'project_id' => $project->id, 'task_id' => $task->id, 'local_runner_id' => $this->runner->id,
            'task_key' => $task->key(), 'branch' => 'ai/'.$task->key().'-ship-reviewed-work', 'path' => 'C:\\EAJ\\workspaces\\shop\\'.$task->key(),
            'base_branch' => 'main', 'base_sha' => str_repeat('a', 40), 'last_checkpoint_sha' => $sha, 'status' => WorkspaceStatus::Ready,
        ]);
        $connection = $this->githubConnection();
        $repository = GithubRepository::create([
            'organization_id' => $this->organization->id, 'github_connection_id' => $connection->id, 'project_id' => $project->id,
            'github_id' => 99, 'owner' => 'acme', 'name' => 'shop', 'full_name' => 'acme/shop', 'default_branch' => 'main',
            'clone_url' => 'https://github.com/acme/shop.git', 'html_url' => 'https://github.com/acme/shop',
        ]);
        $workflow = Workflow::forceCreate([
            'organization_id' => $this->organization->id, 'project_id' => $project->id,
            'subject_type' => $task->getMorphClass(), 'subject_id' => $task->id, 'type' => DeliveryWorkflow::WORKFLOW,
            'status' => WorkflowStatus::Completed, 'current_step' => 1, 'limits' => [], 'started_at' => now(), 'finished_at' => now(),
        ]);
        WorkflowStep::forceCreate([
            'workflow_id' => $workflow->id, 'position' => 1, 'name' => ReviewDecisionStep::NAME,
            'status' => StepStatus::Completed, 'attempt' => 1, 'output' => ['decision' => 'release_ready', 'head_sha' => $sha], 'finished_at' => now(),
        ]);

        return [$project, $task, $workspace, $repository, $sha];
    }

    private function pullPayload(string $branch, string $sha): array
    {
        return [
            'number' => 17, 'node_id' => 'PR_17', 'title' => 'SHOP-1: Ship reviewed work', 'body' => 'body', 'state' => 'open', 'draft' => false,
            'head' => ['ref' => $branch, 'sha' => $sha], 'base' => ['ref' => 'main', 'sha' => str_repeat('a', 40)],
            'html_url' => 'https://github.com/acme/shop/pull/17', 'mergeable_state' => 'clean', 'merged_at' => null,
        ];
    }
}
