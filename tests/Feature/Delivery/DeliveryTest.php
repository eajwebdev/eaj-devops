<?php

namespace Tests\Feature\Delivery;

use App\Agents\AgentCatalog;
use App\AI\Data\ProviderResponse;
use App\AI\Providers\FakeAIProvider;
use App\Delivery\DeliveryWorkflow;
use App\Enums\BlockedReason;
use App\Enums\ModelTier;
use App\Enums\OrganizationRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WorkflowStatus;
use App\Enums\WorkspaceStatus;
use App\Models\AgentRole;
use App\Models\AiModel;
use App\Models\ChangeSet;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectTool;
use App\Models\RepositoryWorkspace;
use App\Models\Review;
use App\Models\RunnerCommand;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Runner\RunnerGateway;
use App\Tools\RunnerWaiter;
use App\Tools\ToolRunService;
use App\Workflow\WorkflowEngine;
use Closure;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Runner\RunnerTestCase;

/**
 * AI delivery (Phase 7 → 8): implement → quality gates → fix cycles → QA,
 * senior and security review → Release Ready or changes (§33, §48.8, §87).
 */
class DeliveryTest extends RunnerTestCase
{
    private Project $project;

    private Task $task;

    /** @var array<string, string> the workspace on the "PC" */
    private array $fs = [];

    /** @var array<string, string> the workspace at its base commit */
    private array $base = [];

    /** @var array<string, list<array<string, mixed>>> scripted tool results, consumed in order */
    private array $toolResults = [];

    private int $checkpoints = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        app(AgentCatalog::class)->ensure($this->organization);
        foreach ($this->organization->modelRoutes()->get() as $route) {
            $tier = $route->tier instanceof ModelTier ? $route->tier : ModelTier::from($route->tier);
            $model = AiModel::firstOrCreate(
                ['organization_id' => $this->organization->id, 'provider' => 'fake', 'model_key' => "fake-{$tier->value}"],
                ['display_name' => "Fake {$tier->value}", 'tier' => $tier],
            );
            $route->forceFill(['primary_model_id' => $model->id])->save();
        }

        $this->runner->forceFill(['version' => '0.3.0', 'capabilities' => ['tool.run', 'workspace.read', 'workspace.apply', 'workspace.diff', 'workspace.checkpoint']])->save();
        $this->project = $this->localProject(attributes: ['key' => 'SHOP', 'slug' => 'shop']);
        foreach (['php.format' => 'format', 'php.static' => 'static', 'php.test' => 'test'] as $key => $kind) {
            ProjectTool::forceCreate([
                'project_id' => $this->project->id, 'key' => $key, 'kind' => $kind, 'ecosystem' => 'php', 'label' => ucfirst(str_replace('.', ' ', $key)),
                'command' => $key, 'parser' => 'junit', 'supports_fix' => $key === 'php.format', 'scoped' => $key === 'php.format', 'enabled' => true, 'available' => true,
            ]);
        }

        $this->task = Task::factory()->for($this->project)->status(TaskStatus::Ready)->create([
            'title' => 'Apply a 10% loyalty discount',
            'priority' => TaskPriority::Medium,
            'acceptance_criteria' => ['Loyal customers get 10% off the cart total'],
            'impact' => ['areas' => [], 'expected_files' => [['path' => 'app/Cart.php']], 'expected_tests' => [['path' => 'tests/CartTest.php']], 'agents' => [['role' => 'BE', 'reason' => 'server logic']], 'notes' => []],
            'impact_analyzed_at' => now(),
        ]);
        RepositoryWorkspace::forceCreate([
            'project_id' => $this->project->id, 'task_id' => $this->task->id, 'local_runner_id' => $this->runner->id,
            'task_key' => $this->task->key(), 'branch' => 'ai/SHOP-1-loyalty', 'path' => 'C:\\EAJ\\workspaces\\shop\\SHOP-1',
            'base_branch' => 'main', 'base_sha' => str_repeat('a', 40), 'status' => WorkspaceStatus::Ready,
        ]);

        $this->fs = $this->base = [
            'app/Cart.php' => "<?php\nclass Cart\n{\n    public function total(): int\n    {\n        return \$this->sum();\n    }\n}\n",
            'tests/CartTest.php' => "<?php\n// cart tests\n",
            'config/services.php' => "<?php\nreturn ['stripe' => '[REDACTED STRIPE KEY]'];\n",
        ];
        $this->playRunner();
    }

    public function test_a_task_is_implemented_checked_reviewed_and_reaches_release_ready(): void
    {
        $this->fake()
            ->push($this->implementation([$this->edit('app/Cart.php', 'return $this->sum();', 'return $this->loyal ? (int) round($this->sum() * 0.9) : $this->sum();'), $this->create('tests/LoyaltyTest.php', "<?php\n// loyalty discount test\n")]))
            ->push($this->review(95, acceptance: true))
            ->push($this->review(96))
            ->push($this->review(98));

        $this->actingAs($this->owner)->post("/projects/shop/tasks/{$this->task->number}/delivery")
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'AI delivery of SHOP-1 started.');

        $workflow = Workflow::where('type', DeliveryWorkflow::WORKFLOW)->sole();
        $this->assertSame(WorkflowStatus::Completed, $workflow->status, (string) $workflow->steps()->whereNotNull('last_error')->value('last_error'));
        $this->assertSame(TaskStatus::ReleaseReady, $this->task->fresh()->status);
        $this->assertSame(
            ['IN_PROGRESS', 'TESTING', 'REVIEW', 'SECURITY_REVIEW', 'RELEASE_READY'],
            $this->task->transitions()->reorder()->orderBy('id')->pluck('to_status')->map(fn ($s) => $s instanceof TaskStatus ? $s->value : $s)->all(),
        );

        // What happened on the PC is what the change set says, not what the agent claimed.
        $this->assertStringContainsString('round($this->sum() * 0.9)', $this->fs['app/Cart.php']);
        $this->assertArrayHasKey('tests/LoyaltyTest.php', $this->fs);
        $changeSet = ChangeSet::sole();
        $this->assertSame('applied', $changeSet->status);
        $this->assertSame(['app/Cart.php', 'tests/LoyaltyTest.php'], collect($changeSet->files)->pluck('path')->sort()->values()->all());
        $this->assertSame([], $changeSet->out_of_scope);
        $this->assertStringContainsString('+++ b/app/Cart.php', $changeSet->diff);

        $this->assertSame(['qa', 'senior', 'security'], Review::orderBy('id')->pluck('gate')->all());
        $this->assertTrue(Review::all()->every->passed);
        $this->assertDatabaseHas('outbox_events', ['type' => 'delivery.release_ready', 'project_id' => $this->project->id]);

        // Reviewers saw the diff, not the whole repository (§53).
        $senior = json_decode($this->fake()->requests[2]->input, true);
        $this->assertStringContainsString('round($this->sum() * 0.9)', $senior['diff']);
        $this->assertSame('senior', $senior['gate']);

        // The owner still approves (§56).
        $this->actingAs($this->owner)->get("/projects/shop/tasks/{$this->task->number}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('delivery.workflow.status', 'COMPLETED')
                ->where('delivery.totals.files', 2)
                ->where('task.status', 'RELEASE_READY'));
    }

    public function test_edits_that_do_not_apply_get_one_correction_round(): void
    {
        $this->fake()
            ->push($this->implementation([$this->edit('app/Cart.php', 'return $this->total;', 'return 0;')]))
            ->push($this->implementation([$this->edit('app/Cart.php', 'return $this->sum();', 'return $this->sum() - $this->discount();')]))
            ->push($this->review(95, acceptance: true))->push($this->review(95))->push($this->review(95));

        app(DeliveryWorkflow::class)->start($this->task, $this->owner);

        $correction = $this->fake()->requests[1]->input;
        $this->assertStringContainsString('could not be used', $correction);
        $this->assertStringContainsString('the search text was not found', $correction);
        $this->assertStringContainsString('$this->discount()', $this->fs['app/Cart.php']);
        $this->assertSame(TaskStatus::ReleaseReady, $this->task->fresh()->status);
    }

    public function test_answers_that_still_do_not_apply_stop_the_delivery_and_nothing_is_written(): void
    {
        $bad = $this->implementation([$this->edit('config/services.php', "'[REDACTED STRIPE KEY]'", "env('STRIPE_SECRET')")]);
        $this->fake()->push($bad)->push($bad);

        app(DeliveryWorkflow::class)->start($this->task, $this->owner);

        $workflow = Workflow::where('type', DeliveryWorkflow::WORKFLOW)->sole();
        $this->assertSame(WorkflowStatus::Blocked, $workflow->status);
        $this->assertSame($this->base, $this->fs, 'no file was touched');
        $this->assertSame(0, RunnerCommand::where('type', 'workspace.apply')->count());
        $task = $this->task->fresh();
        $this->assertSame(TaskStatus::Blocked, $task->status);
        $this->assertStringContainsString('read-only for agents', $task->blocked_reason);
    }

    public function test_failing_gates_go_back_to_the_developer_with_the_parsed_failures(): void
    {
        $this->toolResults['php.test'] = [
            ['status' => 'failed', 'tests' => ['total' => 3, 'passed' => 2, 'failed' => 1, 'skipped' => 0], 'failures' => [['name' => 'CartTest › applies loyalty discount', 'file' => 'tests/CartTest.php', 'message' => 'Failed asserting that 100 matches expected 90.']]],
            ['status' => 'passed', 'tests' => ['total' => 3, 'passed' => 3, 'failed' => 0, 'skipped' => 0]],
        ];
        $this->fake()
            ->push($this->implementation([$this->edit('app/Cart.php', 'return $this->sum();', 'return $this->sum(); // TODO discount')]))
            ->push($this->implementation([$this->edit('app/Cart.php', 'return $this->sum(); // TODO discount', 'return (int) round($this->sum() * 0.9);')], summary: 'Applied the discount the test expects.'))
            ->push($this->review(95, acceptance: true))->push($this->review(95))->push($this->review(95));

        app(DeliveryWorkflow::class)->start($this->task, $this->owner);

        $fix = json_decode($this->fake()->requests[1]->input, true);
        $this->assertSame('fix', $fix['mode']);
        $this->assertSame('Failed asserting that 100 matches expected 90.', $fix['feedback']['failing_tools'][0]['failures'][0]['message']);
        $this->assertSame(['implement', 'fix'], ChangeSet::orderBy('id')->pluck('mode')->all());
        $this->assertSame(TaskStatus::ReleaseReady, $this->task->fresh()->status);
        $this->assertTrue($this->task->transitions()->where('reason', 'like', 'Fix cycle 1 of 3%')->exists());
    }

    public function test_a_fix_goes_only_to_the_developer_whose_change_failed(): void
    {
        $this->fs['resources/js/cart.ts'] = "export const total = 1;\n";
        $this->task->forceFill(['impact' => [...$this->task->impact, 'agents' => [['role' => 'FE', 'reason' => 'ui'], ['role' => 'BE', 'reason' => 'server']], 'expected_files' => [['path' => 'app/Cart.php'], ['path' => 'resources/js/cart.ts']]]])->save();
        $this->toolResults['php.test'] = [
            ['status' => 'failed', 'tests' => ['total' => 1, 'passed' => 0, 'failed' => 1, 'skipped' => 0], 'failures' => [['name' => 'CartTest › discount', 'file' => 'app/Cart.php', 'message' => 'wrong total']]],
        ];
        $this->fake()
            ->push($this->implementation([$this->edit('app/Cart.php', 'return $this->sum();', 'return $this->sum() - 1;')]))   // BE (server first)
            ->push(new ProviderResponse([...$this->implementationBody([$this->edit('resources/js/cart.ts', 'total = 1', 'total = 0.9')]), 'agent' => 'FE', 'summary' => 'UI'], 900, 0, 200, 'req'))
            ->push($this->implementation([$this->edit('app/Cart.php', 'return $this->sum() - 1;', 'return (int) round($this->sum() * 0.9);')]))
            ->push($this->review(95, acceptance: true))->push($this->review(95))->push($this->review(95));

        app(DeliveryWorkflow::class)->start($this->task, $this->owner);

        $this->assertSame([['BE', 'implement'], ['FE', 'implement'], ['BE', 'fix']], ChangeSet::orderBy('id')->get()->map(fn ($c) => [$c->role, $c->mode])->all());
        $this->assertSame(TaskStatus::ReleaseReady, $this->task->fresh()->status);
    }

    public function test_fix_cycles_are_limited_and_the_owner_decides_to_continue(): void
    {
        $failing = ['status' => 'failed', 'tests' => ['total' => 1, 'passed' => 0, 'failed' => 1, 'skipped' => 0], 'failures' => [['name' => 'CartTest › x', 'file' => 'tests/CartTest.php', 'message' => 'still broken']]];
        $this->toolResults['php.test'] = array_fill(0, 4, $failing);
        for ($i = 0; $i < 4; $i++) {
            $this->fake()->push($this->implementation([$this->edit('app/Cart.php', $i === 0 ? 'return $this->sum();' : "return \$this->sum() + {$i};", 'return $this->sum() + '.($i + 1).';')]));
        }

        app(DeliveryWorkflow::class)->start($this->task, $this->owner);

        $workflow = Workflow::where('type', DeliveryWorkflow::WORKFLOW)->sole();
        $this->assertSame(WorkflowStatus::Blocked, $workflow->status);
        $this->assertSame(BlockedReason::LimitReached, $workflow->blocked_reason);
        $this->assertSame(4, ChangeSet::count(), 'the first implementation and three fix cycles');
        $this->assertStringContainsString('Quality gates still failing after 3 fix cycles', $this->task->fresh()->blocked_reason);

        // Resuming is the owner's decision: one more cycle, which now passes.
        $this->toolResults['php.test'] = [['status' => 'passed', 'tests' => ['total' => 1, 'passed' => 1, 'failed' => 0, 'skipped' => 0]]];
        $this->fake()->push($this->implementation([$this->edit('app/Cart.php', 'return $this->sum() + 4;', 'return (int) round($this->sum() * 0.9);')]))
            ->push($this->review(95, acceptance: true))->push($this->review(95))->push($this->review(95));

        $this->actingAs($this->owner)->post("/projects/shop/tasks/{$this->task->number}/delivery/resume")->assertSessionHas('status', 'AI delivery resumed.');

        $this->assertSame(WorkflowStatus::Completed, $workflow->fresh()->status);
        $this->assertSame(TaskStatus::ReleaseReady, $this->task->fresh()->status);
    }

    public function test_the_studio_not_the_reviewer_decides_and_findings_go_back_as_changes(): void
    {
        $this->fake()
            ->push($this->implementation([$this->edit('app/Cart.php', 'return $this->sum();', 'return $this->sum() * 0.9;')]))
            ->push($this->review(95, acceptance: true))
            // A high score with a MAJOR finding does not pass (§98); a made-up location is dropped (§48.8).
            ->push($this->review(95, issues: [
                ['severity' => 'MAJOR', 'file' => 'app/Cart.php', 'line' => 6, 'issue' => 'total() returns a float from an int method.', 'recommendation' => 'Round and cast to int.'],
                ['severity' => 'MINOR', 'file' => 'app/Payments/Gateway.php', 'line' => 3, 'issue' => 'Gateway retries are unbounded.', 'recommendation' => 'Cap retries.'],
            ]))
            ->push($this->review(97))
            ->push($this->implementation([$this->edit('app/Cart.php', 'return $this->sum() * 0.9;', 'return (int) round($this->sum() * 0.9);')], summary: 'Rounded and cast as the senior review asked.'))
            ->push($this->review(95, acceptance: true))->push($this->review(96))->push($this->review(97));

        app(DeliveryWorkflow::class)->start($this->task, $this->owner);

        $first = Review::where('gate', 'senior')->orderBy('id')->first();
        $this->assertFalse($first->passed);
        $this->assertSame(['MAJOR: total() returns a float from an int method. (app/Cart.php:6)'], $first->blocking);
        $this->assertNull($first->issues[1]['file']);
        $this->assertStringContainsString('app/Payments/Gateway.php', $first->warnings[0]);

        $changes = json_decode($this->fake()->requests[4]->input, true);
        $this->assertSame('changes', $changes['mode']);
        $this->assertSame('Round and cast to int.', collect($changes['feedback']['reviews'])->firstWhere('gate', 'senior')['issues'][0]['recommendation']);

        $this->assertTrue($this->task->transitions()->where('to_status', TaskStatus::ChangesRequested)->exists());
        $this->assertSame(TaskStatus::ReleaseReady, $this->task->fresh()->status);
        $this->assertSame(6, Review::count());
    }

    public function test_a_developer_who_needs_input_stops_the_delivery_with_the_question(): void
    {
        $this->fake()->push(new ProviderResponse([
            ...$this->implementationBody([]), 'status' => 'blocked', 'questions' => ['Should the discount apply before or after tax?'],
        ], 100, 0, 50, 'req'));

        app(DeliveryWorkflow::class)->start($this->task, $this->owner);

        $this->assertSame(TaskStatus::Blocked, $this->task->fresh()->status);
        $this->assertStringContainsString('before or after tax', $this->task->fresh()->blocked_reason);
        $this->assertSame(0, ChangeSet::count());
    }

    public function test_a_run_whose_context_cannot_fit_its_budget_stops_before_any_call(): void
    {
        $this->organization->agents()->whereHas('role', fn ($q) => $q->where('key', 'BE'))->update(['token_budget' => 50]);

        app(DeliveryWorkflow::class)->start($this->task, $this->owner);

        $this->assertSame([], $this->fake()->requests, 'nothing was paid for');
        $task = $this->task->fresh();
        $this->assertSame(TaskStatus::Blocked, $task->status);
        $this->assertMatchesRegularExpression('/needs about [\d,]+ input tokens, but only 50 remain in the run budget/', $task->blocked_reason);
    }

    public function test_changes_outside_the_declared_scope_are_flagged_for_the_reviewers(): void
    {
        $this->fs['app/Billing/Invoice.php'] = "<?php\nclass Invoice {}\n";
        $this->fake()
            ->push($this->implementation([
                $this->edit('app/Cart.php', 'return $this->sum();', 'return (int) round($this->sum() * 0.9);'),
                $this->create('app/Billing/LoyaltyNote.php', "<?php\n// note\n"),
            ]))
            ->push($this->review(95, acceptance: true))->push($this->review(95))->push($this->review(95));

        app(DeliveryWorkflow::class)->start($this->task, $this->owner);

        $this->assertSame(['app/Billing/LoyaltyNote.php'], ChangeSet::sole()->out_of_scope);
        $senior = json_decode($this->fake()->requests[2]->input, true);
        $this->assertSame(['app/Billing/LoyaltyNote.php'], $senior['changes'][0]['out_of_scope']);
    }

    public function test_delivery_explains_what_is_missing_and_is_tenant_scoped(): void
    {
        $this->runner->forceFill(['capabilities' => ['tool.run']])->save();
        $this->actingAs($this->owner)->post("/projects/shop/tasks/{$this->task->number}/delivery")->assertSessionHasErrors('delivery');

        $this->runner->forceFill(['capabilities' => ['tool.run', 'workspace.read', 'workspace.apply', 'workspace.diff']])->save();
        $this->organization->modelRoutes()->where('agent_role_id', AgentRole::where('key', 'SE')->value('id'))->update(['enabled' => false]);
        $this->actingAs($this->owner)->post("/projects/shop/tasks/{$this->task->number}/delivery")
            ->assertSessionHasErrors(['delivery' => 'AI delivery cannot start: no AI model is routed to Security Engineer. Fix it under AI Team → Models and routes.']);

        $viewer = User::factory()->memberOf($this->organization, OrganizationRole::Viewer)->create();
        $this->actingAs($viewer)->post("/projects/shop/tasks/{$this->task->number}/delivery")->assertForbidden();

        $other = Organization::factory()->create();
        $stranger = User::factory()->memberOf($other, OrganizationRole::Owner)->create();
        Project::factory()->for($other)->create(['slug' => 'shop']);
        $this->actingAs($stranger)->post("/projects/shop/tasks/{$this->task->number}/delivery")->assertNotFound();
        $this->assertSame(0, Workflow::count());
    }

    public function test_cancelling_leaves_the_task_blocked_and_keeps_the_changes(): void
    {
        $this->fake()->push($this->implementation([$this->edit('app/Cart.php', 'return $this->sum();', 'return 1;')]));
        // The owner cancels while the PC is checkpointing the agent's change.
        $this->onCommand = function (RunnerCommand $c) {
            if ($c->type->value !== 'workspace.checkpoint') {
                return false;
            }
            app(WorkflowEngine::class)->cancel(Workflow::where('type', DeliveryWorkflow::WORKFLOW)->sole(), 'Owner changed their mind');

            return true;
        };

        app(DeliveryWorkflow::class)->start($this->task, $this->owner);

        $this->assertSame(WorkflowStatus::Cancelled, Workflow::where('type', DeliveryWorkflow::WORKFLOW)->sole()->status);
        $task = $this->task->fresh();
        $this->assertSame(TaskStatus::Blocked, $task->status);
        $this->assertStringContainsString('AI delivery cancelled', $task->blocked_reason);
        $this->assertSame(1, ChangeSet::count());
    }

    // --- the PC and the agents --------------------------------------------------------------------

    /** Returns true to leave a command unanswered (the PC went quiet). */
    private ?Closure $onCommand = null;

    private function playRunner(): void
    {
        $test = $this;
        $waiter = new class(app(ToolRunService::class), fn () => $test->answerNextCommand()) extends RunnerWaiter
        {
            private int $idle = 0;

            public function __construct(ToolRunService $tools, private readonly Closure $act)
            {
                parent::__construct($tools);
            }

            protected function pause(): void
            {
                // A step waiting on a PC that never answers gives up instead of spinning forever.
                if (! ($this->act)() && ++$this->idle > 50) {
                    throw new \RuntimeException('The runner did not answer.');
                }
            }
        };
        $this->app->instance(RunnerWaiter::class, $waiter);
    }

    public function answerNextCommand(): bool
    {
        $gateway = app(RunnerGateway::class);
        $signed = $gateway->claim($this->runner->fresh());
        if (! $signed) {
            return false;
        }

        $command = RunnerCommand::findOrFail($signed['id']);
        if ($this->onCommand && ($this->onCommand)($command)) {
            return true;
        }

        $p = $command->payload;
        $result = match ($command->type->value) {
            'workspace.read' => ['head_sha' => $this->sha($this->checkpoints), 'files' => array_map(fn ($path) => $this->fileStatus($path), $p['paths'])],
            'workspace.apply' => $this->applyChanges($p['changes']),
            'workspace.diff' => ['head_sha' => $this->sha($this->checkpoints), 'base_sha' => $p['base_sha'], ...$this->diffSince($this->base)],
            'workspace.checkpoint' => ['sha' => $this->sha(++$this->checkpoints), 'changed' => true, 'files' => []],
            'tool.run' => [
                'tool' => $p['tool'], 'status' => 'passed', 'exit_code' => 0, 'duration_ms' => 900, 'head_sha' => $this->sha($this->checkpoints),
                'tests' => null, 'failures' => [], 'issues' => [], 'files_changed' => [], 'flaky' => [], 'notes' => [], 'output_tail' => '',
                ...(isset($this->toolResults[$p['tool']]) ? (array_shift($this->toolResults[$p['tool']]) ?? []) : []),
            ],
        };

        if (isset($result['__error'])) {
            $gateway->complete($command, false, null, $result['__error'], $result['__message']);
        } else {
            $gateway->complete($command, true, $result);
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function fileStatus(string $path): array
    {
        if (! array_key_exists($path, $this->fs)) {
            return ['path' => $path, 'status' => 'missing'];
        }

        return ['path' => $path, 'status' => 'ok', 'sha1' => sha1($this->fs[$path]), 'size' => strlen($this->fs[$path]), 'redacted' => str_contains($this->fs[$path], '[REDACTED'), 'content' => $this->fs[$path]];
    }

    /** @param list<array<string, mixed>> $changes */
    private function applyChanges(array $changes): array
    {
        foreach ($changes as $c) {
            $current = isset($this->fs[$c['path']]) ? sha1($this->fs[$c['path']]) : null;
            if ($current !== $c['base_sha1']) {
                return ['__error' => 'BASE_CHANGED', '__message' => "{$c['path']} changed since the agent read it."];
            }
        }
        $before = $this->fs;
        foreach ($changes as $c) {
            if ($c['action'] === 'delete') {
                unset($this->fs[$c['path']]);
            } else {
                $this->fs[$c['path']] = $c['content'];
            }
        }

        return ['applied' => count($changes), ...$this->diffSince($before, array_column($changes, 'path'))];
    }

    /**
     * @param  array<string, string>  $before
     * @param  list<string>|null  $only
     * @return array{files: list<array<string, mixed>>, diff: string, diff_truncated: bool}
     */
    private function diffSince(array $before, ?array $only = null): array
    {
        $paths = collect(array_keys($before + $this->fs))->filter(fn ($p) => ($before[$p] ?? null) !== ($this->fs[$p] ?? null))
            ->when($only, fn ($c) => $c->intersect($only))->sort()->values();

        return [
            'files' => $paths->map(fn ($p) => ['path' => $p, 'additions' => substr_count($this->fs[$p] ?? '', "\n"), 'deletions' => substr_count($before[$p] ?? '', "\n")])->all(),
            'diff' => $paths->map(fn ($p) => "diff --git a/{$p} b/{$p}\n--- a/{$p}\n+++ b/{$p}\n".collect(explode("\n", $this->fs[$p] ?? ''))->map(fn ($l) => "+{$l}")->join("\n"))->join("\n"),
            'diff_truncated' => false,
        ];
    }

    public function sha(int $n): string
    {
        return str_pad(dechex($n), 40, 'c', STR_PAD_LEFT);
    }

    private function fake(): FakeAIProvider
    {
        return app(FakeAIProvider::class);
    }

    /** @param list<array<string, mixed>> $changes */
    private function implementation(array $changes, string $summary = 'Added the loyalty discount.'): ProviderResponse
    {
        return new ProviderResponse([...$this->implementationBody($changes), 'summary' => $summary], 1200, 0, 400, 'req');
    }

    /** @param list<array<string, mixed>> $changes */
    private function implementationBody(array $changes): array
    {
        return ['task_id' => 'SHOP-1', 'agent' => 'BE', 'status' => 'completed', 'summary' => '', 'confidence' => 'HIGH', 'changes' => $changes, 'tests_added' => [], 'questions' => [], 'warnings' => []];
    }

    /** @return array<string, mixed> */
    private function edit(string $path, string $search, string $replace): array
    {
        return ['path' => $path, 'action' => 'edit', 'edits' => [['search' => $search, 'replace' => $replace]], 'content' => null];
    }

    /** @return array<string, mixed> */
    private function create(string $path, string $content): array
    {
        return ['path' => $path, 'action' => 'create', 'edits' => [], 'content' => $content];
    }

    /** @param list<array<string, mixed>> $issues */
    private function review(int $score, array $issues = [], bool $acceptance = false): ProviderResponse
    {
        return new ProviderResponse([
            'task_id' => 'SHOP-1', 'agent' => 'XX', 'summary' => 'Reviewed the diff.', 'score' => $score, 'confidence' => 'HIGH',
            'issues' => $issues,
            'acceptance_criteria' => $acceptance ? [['criterion' => 'Loyal customers get 10% off the cart total', 'met' => true, 'evidence' => 'Cart::total() applies 0.9; tests pass.']] : [],
            'warnings' => [],
        ], 900, 0, 200, 'req');
    }
}
