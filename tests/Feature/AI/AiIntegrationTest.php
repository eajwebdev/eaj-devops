<?php

namespace Tests\Feature\AI;

use App\Agents\AgentCatalog;
use App\Agents\AgentRunService;
use App\AI\Data\ProviderRequest;
use App\AI\Data\ProviderResponse;
use App\AI\Exceptions\ProviderException;
use App\AI\Exceptions\RunInterruptedException;
use App\AI\Orchestrator;
use App\AI\PromptCatalog;
use App\AI\ProviderCircuitBreaker;
use App\AI\ProviderExecutor;
use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\FakeAIProvider;
use App\AI\Providers\OpenAIProvider;
use App\AI\StructuredOutputValidator;
use App\Enums\AgentRunStatus;
use App\Enums\ModelTier;
use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Enums\WorkflowStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AiModel;
use App\Models\AiProviderCall;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\PromptVersion;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Reliability\HealthCheck;
use App\Reliability\SystemHalt;
use App\Tasks\TaskStateMachine;
use App\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->memberOf($this->organization, OrganizationRole::Owner)->create();
        $this->project = Project::factory()->for($this->organization)->create(['key' => 'BOOK', 'slug' => 'booking']);
        app(AgentCatalog::class)->ensure($this->organization);
        Http::preventStrayRequests();
    }

    public function test_the_orchestrator_activates_only_the_needed_specialists_and_records_a_validated_plan(): void
    {
        $this->route('fake');
        $task = $this->readyTask(['BE', 'QA', 'SR']);
        $this->fake()
            // The backend specialist claims work no tool did in Phase 6 — the guard must remove it.
            ->push($this->response(['summary' => 'Add an ExpensesExport class and a route.', 'files_modified' => ['app/Exports/ExpensesExport.php'], 'tests_passed' => 12]))
            ->push($this->response(['summary' => 'Feature tests for the export permissions.']));

        $this->actingAs($this->owner)
            ->post("/projects/booking/tasks/{$task->number}/ai")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'AI planning started with 2 specialists.');

        $this->assertSame(TaskStatus::Ready, $task->fresh()->status);
        $this->assertStringContainsString('AI plan ready from 2 specialists', $task->transitions()->reorder()->latest('id')->value('reason'));
        $workflow = Workflow::where('type', Orchestrator::WORKFLOW)->sole();
        $this->assertSame(WorkflowStatus::Completed, $workflow->status);

        $runs = AgentRun::with('role')->orderBy('id')->get();
        $this->assertSame(['BE', 'QA'], $runs->pluck('role.key')->all());
        $this->assertTrue($runs->every(fn (AgentRun $run) => $run->status === AgentRunStatus::Completed && $run->prompt_version_id !== null));
        $this->assertSame(2, AiProviderCall::where('status', 'completed')->count());
        $this->assertSame(2 * 150, (int) $runs->sum('total_tokens'));

        $backend = $runs->first()->output['result'];
        $this->assertSame([], $backend['files_modified']);
        $this->assertSame(0, $backend['tests_passed']);
        $this->assertFalse($backend['ready_for_review']);
        $this->assertStringContainsString('Removed unverified claims (files modified, tests passed)', implode(' ', $backend['warnings']));

        $this->assertDatabaseHas('outbox_events', ['type' => 'agent.status.changed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai.orchestrated']);

        $this->actingAs($this->owner)->get("/projects/booking/tasks/{$task->number}")->assertInertia(fn (Assert $page) => $page
            ->where('aiPlan.status', 'COMPLETED')
            ->has('aiPlan.specialists', 2)
            ->where('aiPlan.specialists.0.role', 'BE')
            ->where('aiPlan.specialists.0.run.status', 'COMPLETED')
            ->where('aiPlan.specialists.0.result.summary', 'Add an ExpensesExport class and a route.')
            ->where('aiPlan.skipped.0.role', 'SR'));
    }

    public function test_invalid_output_gets_exactly_one_repair_request(): void
    {
        $this->route('fake');
        $task = $this->readyTask(['BE']);
        $this->fake()
            ->push(new ProviderResponse(['summary' => 'missing everything else']))
            ->push($this->response(['summary' => 'Repaired.']));

        app(Orchestrator::class)->plan($task, $this->owner);

        $this->assertSame(TaskStatus::Ready, $task->fresh()->status);
        $this->assertSame(['main', 'repair'], AiProviderCall::orderBy('id')->get()->map(fn ($c) => $c->is_repair ? 'repair' : 'main')->all());
        $this->assertSame('Repaired.', AgentRun::sole()->output['result']['summary']);
    }

    public function test_a_second_invalid_output_fails_the_step_and_is_never_applied(): void
    {
        $this->route('fake');
        $task = $this->readyTask(['BE']);
        $this->fake()->push(new ProviderResponse(['summary' => 1]))->push(new ProviderResponse(['summary' => 2]));

        app(Orchestrator::class)->plan($task, $this->owner);

        $run = AgentRun::sole();
        $this->assertSame(AgentRunStatus::Failed, $run->status);
        $this->assertNull($run->output);
        $this->assertSame(WorkflowStatus::Blocked, Workflow::sole()->status);
        // The task never stays in Planning once nothing is running (G6).
        $task->refresh();
        $this->assertSame(TaskStatus::Blocked, $task->status);
        $this->assertStringStartsWith('AI planning stopped:', $task->blocked_reason);
        // The owner can put it back in the queue (or cancel), not "release" it into Planning with nothing running.
        $this->assertSame([TaskStatus::Ready, TaskStatus::Cancelled], TaskStateMachine::userTargets($task));
    }

    public function test_an_owner_resuming_a_blocked_plan_retries_with_a_new_run(): void
    {
        $this->route('fake');
        $task = $this->readyTask(['BE']);
        $this->fake()->push(new ProviderException('Bad request', 'fake', 'invalid_request'));
        app(Orchestrator::class)->plan($task, $this->owner);
        $this->assertSame(TaskStatus::Blocked, $task->fresh()->status);

        $this->fake()->push($this->response(['summary' => 'Second time lucky.']));
        app(WorkflowEngine::class)->resume(Workflow::sole());

        $this->assertSame(TaskStatus::Ready, $task->fresh()->status);
        $this->assertSame([AgentRunStatus::Failed, AgentRunStatus::Completed], AgentRun::orderBy('id')->pluck('status')->all());
        $this->assertSame(WorkflowStatus::Completed, Workflow::sole()->status);
    }

    public function test_timeouts_retry_once_then_fall_back_to_the_other_provider_and_the_fallback_is_audited(): void
    {
        config(['ai.providers.openai.key' => 'sk-test', 'ai.providers.anthropic.key' => 'sk-ant-test']);
        $this->route('openai', 'anthropic');
        $task = $this->readyTask(['BE']);
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'Overloaded', 'code' => 'server_error']], 503),
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_1',
                'content' => [['type' => 'text', 'text' => json_encode($this->agentResult(['summary' => 'From the fallback.']))]],
                'usage' => ['input_tokens' => 90, 'output_tokens' => 30, 'cache_read_input_tokens' => 10],
            ]),
        ]);

        app(Orchestrator::class)->plan($task, $this->owner);

        $calls = AiProviderCall::orderBy('id')->get();
        $this->assertSame(['openai:failed', 'openai:failed', 'anthropic:completed'], $calls->map(fn ($c) => "{$c->provider}:{$c->status}")->all());
        $this->assertSame(503, $calls[0]->http_status);
        $this->assertTrue($calls[2]->is_fallback);

        $run = AgentRun::with('model')->sole();
        $this->assertSame(AgentRunStatus::Completed, $run->status);
        $this->assertSame('anthropic', $run->model->provider);
        $this->assertSame(120, (int) $run->total_tokens);
        $this->assertTrue(AuditLog::where('action', 'ai.provider.fallback')->exists());
        $this->assertDatabaseHas('agent_events', ['agent_run_id' => $run->id, 'type' => 'ai.provider.fallback']);
    }

    public function test_an_open_circuit_skips_the_provider_and_goes_straight_to_the_fallback(): void
    {
        config(['ai.providers.openai.key' => 'sk-test', 'ai.providers.anthropic.key' => 'sk-ant-test']);
        $this->route('openai', 'anthropic');
        foreach (range(1, 5) as $_) {
            app(ProviderCircuitBreaker::class)->failure('openai');
        }
        $this->assertTrue(app(ProviderCircuitBreaker::class)->snapshot('openai')['open']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($this->agentResult())]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        app(Orchestrator::class)->plan($this->readyTask(['BE']), $this->owner);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'openai.com'));
        $this->assertSame(['anthropic'], AiProviderCall::pluck('provider')->all());
    }

    public function test_no_provider_call_is_made_while_operations_are_stopped(): void
    {
        $this->route('fake');
        $task = $this->readyTask(['BE']);
        $agent = $this->agent('BE');
        $run = app(AgentRunService::class)->start($task, $agent, 'planning', 'halt-test');
        app(SystemHalt::class)->set($this->organization, $this->owner, 'Budget check', []);
        $this->fake()->push($this->response());

        try {
            app(ProviderExecutor::class)->execute($run, $this->request());
            $this->fail('The provider was called while operations were stopped.');
        } catch (RunInterruptedException) {
        }

        $this->assertSame(0, AiProviderCall::count());
    }

    public function test_the_http_adapters_request_strict_structured_output_and_report_usage(): void
    {
        config(['ai.providers.openai.key' => 'sk-test', 'ai.providers.anthropic.key' => 'sk-ant-test']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'resp_1',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($this->agentResult())]]]],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'input_tokens_details' => ['cached_tokens' => 40]],
            ]),
            'api.anthropic.com/*' => Http::sequence()
                ->push(['error' => ['type' => 'rate_limit_error', 'message' => 'Slow down']], 429, ['Retry-After' => '7']),
        ]);

        $response = app(OpenAIProvider::class)->generate($this->request());
        $this->assertSame(['input' => 100, 'cached' => 40, 'output' => 20], ['input' => $response->inputTokens, 'cached' => $response->cachedInputTokens, 'output' => $response->outputTokens]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/responses'
            && $request['text']['format']['type'] === 'json_schema'
            && $request['text']['format']['strict'] === true
            && $request['store'] === false
            && $request->hasHeader('Authorization', 'Bearer sk-test'));

        try {
            app(AnthropicProvider::class)->generate($this->request());
            $this->fail('Expected a rate-limit error.');
        } catch (ProviderException $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame(7, $e->retryAfter);
            $this->assertSame('rate_limit_error', $e->errorCode);
        }
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request['output_config']['format']['type'] === 'json_schema'
            && $request->hasHeader('x-api-key', 'sk-ant-test'));
    }

    public function test_the_validator_accepts_empty_objects_and_rejects_unknown_or_missing_fields(): void
    {
        $validator = new StructuredOutputValidator;
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['meta'], 'properties' => ['meta' => ['type' => 'object']]];

        $this->assertSame([], $validator->validate(json_decode('{"meta": {}}', true), $schema));
        $this->assertSame(['$.meta is required.', '$.extra is not allowed.'], $validator->validate(['extra' => 1], $schema));
        $this->assertSame([], $validator->validate($this->agentResult(), app(PromptCatalog::class)->schema()));
    }

    public function test_prompt_versions_are_tracked_per_run_and_can_be_rolled_back(): void
    {
        $catalog = app(PromptCatalog::class);
        $v1 = $catalog->activeFor($this->organization, 'BE');
        $v2 = PromptVersion::forceCreate([...$v1->only('organization_id', 'key', 'system_prompt', 'output_schema'), 'version' => '1.1.0', 'active' => false]);

        $catalog->activate($v2);
        $this->assertSame('1.1.0', $catalog->activeFor($this->organization, 'BE')->version);

        $catalog->activate($v1);
        $this->assertSame('1.0.0', $catalog->activeFor($this->organization, 'BE')->version);
        $this->assertSame(1, PromptVersion::where('key', 'agent.be')->where('active', true)->count());
    }

    public function test_only_ready_tasks_can_be_planned_viewers_cannot_start_ai_and_other_tenants_get_404(): void
    {
        $this->route('fake');
        $task = $this->readyTask(['BE']);
        $viewer = User::factory()->memberOf($this->organization, OrganizationRole::Viewer)->create();
        $this->actingAs($viewer)->post("/projects/booking/tasks/{$task->number}/ai")->assertForbidden();

        $backlog = Task::factory()->for($this->project)->create(['status' => TaskStatus::Backlog]);
        $this->actingAs($this->owner)->post("/projects/booking/tasks/{$backlog->number}/ai")
            ->assertSessionHasErrors(['ai' => "Move {$backlog->key()} to Ready before handing it to the AI team."]);

        $other = Organization::factory()->create();
        $foreign = Task::factory()->for(Project::factory()->for($other)->create(['slug' => 'theirs']))->create(['status' => TaskStatus::Ready]);
        $this->actingAs($this->owner)->post("/projects/theirs/tasks/{$foreign->number}/ai")->assertNotFound();

        $this->assertSame(0, Workflow::count());
    }

    public function test_planning_is_refused_when_no_specialist_has_a_model(): void
    {
        $task = $this->readyTask(['BE']);

        $this->actingAs($this->owner)->post("/projects/booking/tasks/{$task->number}/ai")
            ->assertSessionHasErrors('ai');

        $this->assertSame(TaskStatus::Ready, $task->fresh()->status);
        $this->assertSame(0, Workflow::count());
    }

    public function test_health_reports_ai_providers_and_open_circuits_without_exposing_keys(): void
    {
        $ai = fn () => collect(app(HealthCheck::class)->run()['checks'])->firstWhere('key', 'ai');

        config(['ai.providers.openai.key' => null, 'ai.providers.anthropic.key' => null]);
        $this->getJson('/health')->assertJsonPath('checks.ai', 'degraded');

        config(['ai.providers.openai.key' => 'sk-secret-test', 'ai.providers.anthropic.key' => 'sk-ant-secret-test']);
        $this->getJson('/health')->assertJsonPath('checks.ai', 'ok');
        $this->assertSame('OpenAI, Anthropic configured', $ai()['detail']);

        foreach (range(1, 5) as $_) {
            app(ProviderCircuitBreaker::class)->failure('anthropic');
        }
        $this->assertSame('degraded', $ai()['status']);
        $this->assertStringContainsString('Circuit open for Anthropic', $ai()['detail']);
        $this->assertStringNotContainsString('sk-', json_encode(app(HealthCheck::class)->run()));
    }

    private function route(string $provider, ?string $fallbackProvider = null): void
    {
        foreach ($this->organization->modelRoutes()->get() as $route) {
            $tier = $route->tier instanceof ModelTier ? $route->tier : ModelTier::from($route->tier);
            $primary = AiModel::firstOrCreate(
                ['organization_id' => $this->organization->id, 'provider' => $provider, 'model_key' => "{$provider}-{$tier->value}"],
                ['display_name' => ucfirst($provider).' '.$tier->value, 'tier' => $tier],
            );
            $fallback = $fallbackProvider ? AiModel::firstOrCreate(
                ['organization_id' => $this->organization->id, 'provider' => $fallbackProvider, 'model_key' => "{$fallbackProvider}-{$tier->value}"],
                ['display_name' => ucfirst($fallbackProvider).' '.$tier->value, 'tier' => $tier],
            ) : null;
            $route->forceFill(['primary_model_id' => $primary->id, 'fallback_model_id' => $fallback?->id])->save();
        }
    }

    /** @param list<string> $roles */
    private function readyTask(array $roles): Task
    {
        return Task::factory()->for($this->project)->create([
            'status' => TaskStatus::Ready,
            'title' => 'Expenses export to Excel',
            'impact' => [
                'areas' => [],
                'expected_files' => [],
                'expected_tests' => [],
                'agents' => array_map(fn ($role) => ['role' => $role, 'reason' => "{$role} work"], $roles),
                'notes' => [],
            ],
            'impact_analyzed_at' => now(),
        ]);
    }

    private function agent(string $role): Agent
    {
        return $this->organization->agents()->whereHas('role', fn ($q) => $q->where('key', $role))->firstOrFail();
    }

    private function fake(): FakeAIProvider
    {
        return app(FakeAIProvider::class);
    }

    /** @param array<string, mixed> $overrides */
    private function response(array $overrides = []): ProviderResponse
    {
        return new ProviderResponse($this->agentResult($overrides), 100, 0, 50, 'req_fake');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function agentResult(array $overrides = []): array
    {
        return [
            'task_id' => 'BOOK-1', 'agent' => 'BE', 'status' => 'completed', 'summary' => 'Plan.',
            'files_read' => [], 'files_modified' => [], 'tests_added' => [], 'tests_passed' => 0, 'tests_failed' => 0,
            'warnings' => [], 'ready_for_review' => false, ...$overrides,
        ];
    }

    private function request(): ProviderRequest
    {
        return new ProviderRequest('model-x', 'system', '{}', app(PromptCatalog::class)->schema(), 'agent_result', 1000, 'test-key');
    }
}
