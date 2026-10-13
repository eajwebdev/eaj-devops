<?php

namespace Tests\Feature\Runner;

use App\Enums\OrganizationRole;
use App\Jobs\PublishOutboxEvents;
use App\Models\LocalRunner;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Runner\RunnerPairing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class RunnerTestCase extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $owner;

    protected LocalRunner $runner;

    protected string $token;

    protected string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([PublishOutboxEvents::class]);

        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->memberOf($this->organization, OrganizationRole::Owner)->create();
        [$this->runner, $this->token, $this->secret] = $this->pairRunner($this->organization, $this->owner);
    }

    /**
     * @return array{0: LocalRunner, 1: string, 2: string}
     */
    protected function pairRunner(Organization $organization, User $user, string $name = 'Dev PC'): array
    {
        $pairing = app(RunnerPairing::class);
        $code = $pairing->createCode($organization, $user)['code'];
        $result = $pairing->redeem($code, ['name' => $name, 'hostname' => 'DEV-PC', 'platform' => 'win32', 'version' => '0.1.0'], '127.0.0.1');

        $result['runner']->forceFill(['allowed_roots' => ['C:\\laragon\\www'], 'last_heartbeat_at' => now()])->save();

        return [$result['runner'], $result['token'], $result['signing_secret']];
    }

    protected function localProject(?LocalRunner $runner = null, array $attributes = []): Project
    {
        $project = Project::factory()->for($this->organization)->create($attributes);
        $project->connection->forceFill(['local_runner_id' => ($runner ?? $this->runner)->id, 'local_path' => 'C:\\laragon\\www\\'.$project->slug])->save();

        return $project->fresh('connection.runner');
    }

    protected function runnerApi(string $method, string $uri, array $data = [], ?string $token = null): TestResponse
    {
        return $this->withToken($token ?? $this->token)->json($method, "/api/runner/v1/{$uri}", $data);
    }

    /**
     * Claim the next command as the runner would, verifying its signature.
     *
     * @return array{id: string, type: string, payload: array<string, mixed>}
     */
    protected function claimAsRunner(): array
    {
        $command = $this->runnerApi('POST', 'commands/claim')->assertOk()->json('command');
        $this->assertNotNull($command, 'expected a queued command');

        $expected = hash_hmac('sha256', "{$command['id']}\n{$command['type']}\n{$command['issued_at']}\n{$command['payload_json']}", $this->secret);
        $this->assertSame($expected, $command['signature'], 'command must be signed with the runner secret');

        return ['id' => $command['id'], 'type' => $command['type'], 'payload' => json_decode($command['payload_json'], true)];
    }

    /**
     * @return array<string, mixed>
     */
    protected function scanResult(array $overrides = []): array
    {
        return array_replace_recursive([
            'stack' => [
                'name' => 'booking-saas',
                'backend' => ['framework' => 'Laravel', 'version' => '10.48.4', 'constraint' => '^10.10', 'php' => '^8.1'],
                'frontend' => ['framework' => 'React', 'version' => '18.2.0', 'router' => null],
                'bridge' => 'Inertia',
                'bundler' => 'Vite',
                'css' => ['Tailwind CSS 3.4'],
                'language' => 'TypeScript',
                'database' => 'MySQL',
                'tests' => ['Pest'],
                'envShape' => ['DB_CONNECTION' => 'mysql', 'DB_PASSWORD' => '<set>'],
                'commands' => [['label' => 'PHP tests', 'command' => 'vendor/bin/pest']],
            ],
            'git' => ['enabled' => true, 'branch' => 'main', 'hasCommits' => true, 'modified' => 0, 'untracked' => 0],
            'warnings' => [],
        ], $overrides);
    }
}
