<?php

namespace Tests\Feature\Tools;

use App\Actions\Operations\HaltOperations;
use App\Enums\OrganizationRole;
use App\Enums\RunnerCommandStatus;
use App\Enums\RunnerCommandType;
use App\Enums\TaskStatus;
use App\Enums\ToolRunStatus;
use App\Enums\WorkflowStatus;
use App\Enums\WorkspaceStatus;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\ProjectTool;
use App\Models\RepositoryWorkspace;
use App\Models\RunnerCommand;
use App\Models\Task;
use App\Models\ToolRun;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowCheckpoint;
use App\Runner\RunnerGateway;
use App\Tools\QualityChecks;
use App\Tools\RunnerWaiter;
use App\Tools\Steps\QualityReportStep;
use App\Tools\ToolCatalog;
use App\Tools\ToolRunService;
use Closure;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Runner\RunnerTestCase;

/**
 * Phase 7 — Automated Engineering Tools (Plan §33, §34, §48.8, §84, §85).
 */
class EngineeringToolsTest extends RunnerTestCase
{
    private Project $project;

    private Task $task;

    private RepositoryWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner->forceFill(['version' => '0.2.0', 'capabilities' => ['project.scan', 'tool.run']])->save();
        $this->project = $this->localProject(attributes: ['key' => 'BOOK', 'slug' => 'booking']);
        $this->task = Task::factory()->for($this->project)->status(TaskStatus::Ready)->create();
        $this->workspace = RepositoryWorkspace::forceCreate([
            'project_id' => $this->project->id,
            'task_id' => $this->task->id,
            'local_runner_id' => $this->runner->id,
            'task_key' => $this->task->key(),
            'branch' => "ai/{$this->task->key()}-dashboard",
            'path' => 'C:\\EAJ\\workspaces\\booking\\'.$this->task->key(),
            'base_branch' => 'main',
            'base_sha' => str_repeat('a', 40),
            'status' => WorkspaceStatus::Ready,
        ]);
    }

    public function test_a_scan_syncs_the_approved_tool_set_and_keeps_the_owners_choices(): void
    {
        $this->scanWith([
            $this->tool('php.test', 'test', 'PHP tests (Pest)', 'php vendor/bin/pest --colors=never --log-junit <report>'),
            $this->tool('php.format', 'format', 'PHP formatter (Laravel Pint)', 'php vendor/bin/pint --test', supportsFix: true),
            $this->tool('e2e.playwright', 'e2e', 'End-to-end tests (Playwright)', 'playwright test --reporter=line,junit'),
            ['key' => 'rm -rf /', 'kind' => 'test', 'ecosystem' => 'js', 'label' => 'x', 'command' => 'x', 'parser' => 'junit'],
            $this->tool('js.build', 'nuke', 'Bad kind', 'npm run build'),
        ]);

        $tools = $this->project->tools()->get()->keyBy('key');
        $this->assertSame(['e2e.playwright', 'php.format', 'php.test'], $tools->keys()->sort()->values()->all(), 'malformed entries are ignored');
        $this->assertFalse($tools['e2e.playwright']->enabled, 'browser tests are opt-in');
        $this->assertTrue($tools['php.test']->enabled);
        $this->assertDatabaseHas('outbox_events', ['type' => 'project.tools.updated', 'project_id' => $this->project->id]);

        app(ToolCatalog::class)->setEnabled($tools['php.format'], false, $this->owner);

        // The formatter disappears from the project, then comes back: the owner's "off" survives.
        $this->scanWith([$this->tool('php.test', 'test', 'PHP tests (Pest)', 'php vendor/bin/pest')]);
        $this->assertFalse($this->project->tools()->where('key', 'php.format')->value('available'));

        $this->scanWith([$this->tool('php.format', 'format', 'PHP formatter (Laravel Pint)', 'php vendor/bin/pint --test', supportsFix: true)]);
        $format = $this->project->tools()->where('key', 'php.format')->first();
        $this->assertTrue($format->available);
        $this->assertFalse($format->enabled);

        // A pre-0.2 runner reports no tools at all: the set is left alone.
        $this->scanWith(null);
        $this->assertTrue($this->project->tools()->where('key', 'php.format')->value('available'));
    }

    public function test_owners_switch_tools_but_developers_and_other_organizations_cannot(): void
    {
        $tool = $this->seedTools(['php.test'])['php.test'];
        $blocked = $this->seedTool('js.build', 'build', 'Frontend build', blocked: 'The "build" script runs a command that needs explicit approval (Plan §85): rm -rf /');

        $this->actingAs($this->owner)->patch("/projects/booking/tools/{$tool->id}", ['enabled' => false])->assertSessionHasNoErrors();
        $this->assertFalse($tool->fresh()->enabled);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tool.disabled', 'project_id' => $this->project->id]);

        $this->actingAs($this->owner)->patch("/projects/booking/tools/{$blocked->id}", ['enabled' => true])->assertSessionHasErrors('tools');

        $developer = User::factory()->memberOf($this->organization, OrganizationRole::Developer)->create();
        $this->actingAs($developer)->patch("/projects/booking/tools/{$tool->id}", ['enabled' => true])->assertForbidden();

        $other = Organization::factory()->create();
        $stranger = User::factory()->memberOf($other, OrganizationRole::Owner)->create();
        $foreign = Project::factory()->for($other)->create(['slug' => 'booking']);
        $this->actingAs($stranger)->patch("/projects/booking/tools/{$tool->id}", ['enabled' => true])->assertNotFound();
        $this->actingAs($stranger)->post("/projects/booking/tasks/{$this->task->number}/quality")->assertNotFound();
        $this->assertNotNull($foreign);
    }

    public function test_a_tool_runs_on_the_runner_by_key_and_its_parsed_evidence_is_stored_redacted(): void
    {
        $this->seedTools(['php.test']);

        $run = app(ToolRunService::class)->request($this->workspace, 'php.test', actor: $this->owner);
        $this->assertSame(ToolRunStatus::Queued, $run->status);

        $claimed = $this->claimAsRunner();
        $this->assertSame('tool.run', $claimed['type']);
        $this->assertSame('php.test', $claimed['payload']['tool']);
        $this->assertTrue($claimed['payload']['rerun_failed']);
        $this->assertArrayNotHasKey('argv', $claimed['payload'], 'the Studio never sends a command line');
        $this->assertSame(ToolRunStatus::Running, $run->fresh()->status);

        $this->runnerApi('POST', "commands/{$claimed['id']}/progress", ['message' => 'PHP tests (Pest): ✓ creates booking'])
            ->assertOk()->assertJson(['cancel' => false]);
        $this->assertSame('PHP tests (Pest): ✓ creates booking', $run->fresh()->progress);
        $this->assertDatabaseHas('outbox_events', ['type' => 'tool.run.progress', 'project_id' => $this->project->id]);

        $this->runnerApi('POST', "commands/{$claimed['id']}/complete", ['status' => 'succeeded', 'result' => [
            'tool' => 'php.test', 'kind' => 'test', 'label' => 'PHP tests (Pest)', 'command' => 'php vendor/bin/pest --colors=never --log-junit <report>',
            'status' => 'failed', 'exit_code' => 1, 'duration_ms' => 8123, 'head_sha' => str_repeat('b', 40),
            'tests' => ['total' => 12, 'passed' => 11, 'failed' => 1, 'skipped' => 0],
            'failures' => [['name' => 'BookingTest › rejects overlap', 'file' => 'C:\\EAJ\\workspaces\\booking\\tests\\Feature\\BookingTest.php', 'message' => 'Failed asserting 200 is 422', 'flaky' => false]],
            'issues' => [],
            'files_changed' => [],
            'flaky' => [],
            'database' => ['connection' => 'sqlite', 'database' => ':memory:', 'source' => 'injected'],
            'notes' => ['Tests ran on an in-memory SQLite database.'],
            'output_tail' => 'Connecting with STRIPE_SECRET=sk_'.'live_51Habcdefghijklmnopqrstuvwx',
        ]])->assertOk();

        $run->refresh();
        $this->assertSame(ToolRunStatus::Failed, $run->status);
        $this->assertSame('11 passed · 1 failed', $run->summary());
        $this->assertSame(str_repeat('b', 40), $run->head_sha);
        $this->assertSame('BookingTest.php', $run->failures[0]['file'], 'no local folder structure is stored');
        $this->assertStringNotContainsString('sk_'.'live_', $run->output_tail);
        $this->assertSame('injected', $run->database['source']);
        $this->assertDatabaseHas('outbox_events', ['type' => 'tool.run.finished', 'project_id' => $this->project->id]);

        // G4: the same key returns the same run.
        $this->assertTrue(app(ToolRunService::class)->request($this->workspace, 'php.test', idempotencyKey: $run->idempotency_key)->is($run));
    }

    public function test_tools_that_are_off_blocked_or_gone_are_refused_before_anything_is_queued(): void
    {
        $this->seedTool('php.test', 'test', 'PHP tests', enabled: false);
        $this->seedTool('js.build', 'build', 'Frontend build', blocked: 'needs approval');

        foreach (['php.test' => 'switched off', 'js.build' => 'needs approval', 'js.lint' => 'no "js.lint" tool'] as $key => $message) {
            try {
                app(ToolRunService::class)->request($this->workspace, $key);
                $this->fail("{$key} should be refused");
            } catch (\DomainException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->assertSame(0, RunnerCommand::where('type', 'tool.run')->count());
    }

    public function test_cancelling_stops_a_queued_tool_at_once_and_tells_a_running_one_to_stop(): void
    {
        $this->seedTools(['php.test', 'js.build']);
        $service = app(ToolRunService::class);

        $queued = $service->request($this->workspace, 'js.build');
        $service->cancel($queued->fresh(), $this->owner);
        $this->assertSame(ToolRunStatus::Cancelled, $queued->fresh()->status);
        $this->assertSame(RunnerCommandStatus::Cancelled, $queued->runnerCommand->fresh()->status);

        $running = $service->request($this->workspace, 'php.test');
        $claimed = $this->claimAsRunner();
        $service->cancel($running->fresh(), $this->owner);

        $this->runnerApi('POST', "commands/{$claimed['id']}/progress", ['message' => 'still running'])->assertJson(['cancel' => true]);
        $this->runnerApi('POST', "commands/{$claimed['id']}/complete", ['status' => 'failed', 'error' => ['code' => 'CANCELLED', 'message' => 'PHP tests was stopped.']]);
        $this->assertSame(ToolRunStatus::Cancelled, $running->fresh()->status);
    }

    public function test_a_tool_the_runner_refuses_shows_as_blocked_with_its_reason(): void
    {
        $this->seedTools(['php.test']);
        $run = app(ToolRunService::class)->request($this->workspace, 'php.test');
        $claimed = $this->claimAsRunner();

        $this->runnerApi('POST', "commands/{$claimed['id']}/complete", ['status' => 'failed', 'error' => [
            'code' => 'TEST_DB_UNSAFE', 'message' => 'The test configuration points at "booking", the database the project\'s .env uses.',
        ]]);

        $run->refresh();
        $this->assertSame(ToolRunStatus::Blocked, $run->status);
        $this->assertStringContainsString('the database the project', $run->error_message);
    }

    public function test_quality_checks_run_the_gates_in_order_and_report_a_verdict_bound_to_a_commit(): void
    {
        $this->seedTools(['php.install', 'php.format', 'php.static', 'php.test', 'js.build']);
        $this->seedTool('e2e.playwright', 'e2e', 'End-to-end tests', enabled: false);

        $this->scriptRunner([
            'php.install' => ['status' => 'skipped', 'notes' => ['vendor/autoload.php is already present in the workspace.']],
            'php.format' => ['status' => 'passed', 'files_changed' => ['app/Models/Booking.php']],
            'php.static' => ['status' => 'passed', 'issues' => []],
            'php.test' => ['status' => 'failed', 'exit_code' => 1, 'tests' => ['total' => 3, 'passed' => 2, 'failed' => 1, 'skipped' => 0], 'failures' => [['name' => 'BookingTest › rejects overlap', 'file' => 'tests/Feature/BookingTest.php', 'message' => 'Failed asserting 200 is 422']]],
            'js.build' => ['status' => 'passed'],
        ]);

        $this->actingAs($this->owner)
            ->post("/projects/booking/tasks/{$this->task->number}/quality")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Quality checks started: 5 tools queued on the runner.');

        $workflow = Workflow::where('type', QualityChecks::WORKFLOW)->sole();
        $this->assertSame(WorkflowStatus::Completed, $workflow->status);
        $this->assertSame(
            ['tool.run:php.install', 'workspace.checkpoint', 'tool.run:php.format', 'workspace.checkpoint', 'tool.run:php.static', 'tool.run:php.test', 'tool.run:js.build', 'quality.report'],
            $workflow->steps()->orderBy('position')->get()->map(fn ($s) => $s->name.(isset($s->input['tool']) ? ':'.$s->input['tool'] : ''))->all(),
            'install → checkpoint → formatter (fix) → checkpoint → static → tests → build; disabled tools never run',
        );
        $this->assertSame('fix', ToolRun::where('tool_key', 'php.format')->value('mode'));

        $report = $workflow->steps()->where('name', QualityReportStep::NAME)->sole()->output;
        $this->assertFalse($report['passed']);
        $this->assertTrue($report['consistent']);
        $this->assertSame($this->sha(2), $report['head_sha'], 'gates checked the commit made after formatting');
        $gates = collect($report['gates'])->keyBy('kind');
        $this->assertSame('passed', $gates['format']['status']);
        $this->assertSame('passed', $gates['static']['status']);
        $this->assertSame('failed', $gates['test']['status']);
        $this->assertSame('passed', $gates['build']['status']);
        $this->assertSame('not_configured', $gates['e2e']['status']);

        // Each checkpoint's commit is recorded on the workflow (G3).
        $this->assertSame([$this->sha(1), $this->sha(2)], WorkflowCheckpoint::where('workflow_id', $workflow->id)->whereNotNull('commit_sha')->orderBy('id')->pluck('commit_sha')->all());
        $this->assertSame(TaskStatus::Ready, $this->task->fresh()->status, 'checks never claim agent work on the task');

        $event = OutboxEvent::where('type', 'quality.checks.completed')->sole();
        $this->assertFalse($event->payload['passed']);

        // The task page shows the same, from the same rows.
        $this->actingAs($this->owner)->get("/projects/booking/tasks/{$this->task->number}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('quality.workflow.status', 'COMPLETED')
                ->where('quality.report.passed', false)
                ->where('quality.steps.5.run.summary', '2 passed · 1 failed')
                ->where('quality.steps.5.run.failures.0.name', 'BookingTest › rejects overlap'));
    }

    public function test_all_gates_passing_on_one_commit_passes_and_flaky_tests_never_do(): void
    {
        $this->seedTools(['php.static', 'php.test']);

        $this->scriptRunner(['php.static' => ['status' => 'passed'], 'php.test' => ['status' => 'passed', 'tests' => ['total' => 5, 'passed' => 5, 'failed' => 0, 'skipped' => 0]]]);
        app(QualityChecks::class)->start($this->task, $this->owner);
        $this->assertTrue($this->report()['passed']);

        $this->scriptRunner(['php.static' => ['status' => 'passed'], 'php.test' => ['status' => 'flaky', 'flaky' => ['BookingTest › sometimes']]]);
        app(QualityChecks::class)->start($this->task->fresh(), $this->owner);
        $this->assertFalse($this->report()['passed'], 'a flaky test blocks until a person accepts it (§48.8)');

        // Gates that checked different commits do not describe one state of the code.
        $this->scriptRunner(['php.static' => ['status' => 'passed', 'head_sha' => str_repeat('e', 40)], 'php.test' => ['status' => 'passed', 'head_sha' => str_repeat('f', 40)]]);
        app(QualityChecks::class)->start($this->task->fresh(), $this->owner);
        $report = $this->report();
        $this->assertFalse($report['passed']);
        $this->assertFalse($report['consistent']);

        // A check that rewrote files (a lint script with --fix) left later gates checking uncommitted code.
        $this->scriptRunner(['php.static' => ['status' => 'passed', 'files_changed' => ['app/Models/Booking.php']], 'php.test' => ['status' => 'passed']]);
        app(QualityChecks::class)->start($this->task->fresh(), $this->owner);
        $report = $this->report();
        $this->assertFalse($report['passed']);
        $this->assertStringContainsString('changed files while checking', $report['note']);
    }

    public function test_a_failed_dependency_install_skips_the_checks_that_need_it(): void
    {
        $this->seedTools(['php.install', 'php.test', 'js.build']);
        $this->scriptRunner([
            'php.install' => ['status' => 'error', 'exit_code' => 1, 'output_tail' => 'Your requirements could not be resolved'],
            'js.build' => ['status' => 'passed'],
        ]);

        app(QualityChecks::class)->start($this->task, $this->owner);

        $this->assertSame(0, ToolRun::where('tool_key', 'php.test')->count(), 'PHP tests never ran without their dependencies');
        $this->assertSame(1, ToolRun::where('tool_key', 'js.build')->count(), 'other ecosystems still run');
        $gates = collect($this->report()['gates'])->keyBy('kind');
        $this->assertSame('not_run', $gates['test']['status']);
        $this->assertStringContainsString('did not succeed', $gates['test']['runs'][0]['summary']);
    }

    public function test_quality_checks_explain_what_is_missing(): void
    {
        $this->actingAs($this->owner)->post("/projects/booking/tasks/{$this->task->number}/quality")
            ->assertSessionHasErrors(['quality' => 'No quality tools are enabled for this project. Re-scan it, or switch tools on under Engineering tools.']);

        $this->seedTools(['php.test']);

        $this->runner->forceFill(['capabilities' => ['project.scan']])->save();
        $this->actingAs($this->owner)->post("/projects/booking/tasks/{$this->task->number}/quality")->assertSessionHasErrors('quality');

        $this->runner->forceFill(['capabilities' => ['tool.run'], 'last_heartbeat_at' => now()->subMinutes(10)])->save();
        $this->actingAs($this->owner)->post("/projects/booking/tasks/{$this->task->number}/quality")
            ->assertSessionHasErrors(['quality' => "The runner for {$this->task->key()} is offline. Start it on that PC (eaj-runner start) and try again."]);

        $this->workspace->forceFill(['status' => WorkspaceStatus::Creating])->save();
        $this->actingAs($this->owner)->post("/projects/booking/tasks/{$this->task->number}/quality")->assertSessionHasErrors('quality');

        $viewer = User::factory()->memberOf($this->organization, OrganizationRole::Viewer)->create();
        $this->actingAs($viewer)->post("/projects/booking/tasks/{$this->task->number}/quality")->assertForbidden();
        $this->assertSame(0, Workflow::count());
    }

    public function test_stop_ai_pauses_the_checks_and_the_running_tool_is_told_to_stop(): void
    {
        $this->seedTools(['php.static', 'php.test']);

        // The runner picks up the first tool, then the owner pulls the emergency stop mid-run.
        $this->scriptRunner([], function (RunnerCommand $command) {
            if ($command->type->value !== 'tool.run') {
                return false;
            }

            app(HaltOperations::class)->handle($this->owner, $this->organization, 'Emergency stop');

            return true;
        });

        app(QualityChecks::class)->start($this->task, $this->owner);

        $workflow = Workflow::where('type', QualityChecks::WORKFLOW)->sole();
        $this->assertSame(WorkflowStatus::Paused, $workflow->status);

        // Still running on the PC until the runner says it stopped (G6) — but it has been told to stop.
        $run = ToolRun::where('tool_key', 'php.static')->sole();
        $this->assertSame(ToolRunStatus::Running, $run->status);
        $this->assertNotNull($run->runnerCommand->cancel_requested_at);
        $this->runnerApi('POST', "commands/{$run->runner_command_id}/progress", ['message' => 'x'])->assertJson(['cancel' => true]);

        $this->runnerApi('POST', "commands/{$run->runner_command_id}/complete", ['status' => 'failed', 'error' => ['code' => 'CANCELLED', 'message' => 'Static analysis was stopped.']]);
        $this->assertSame(ToolRunStatus::Cancelled, $run->fresh()->status);
        $this->assertSame(0, ToolRun::where('tool_key', 'php.test')->count(), 'nothing after the stop ran');

        $this->actingAs($this->owner)->post("/projects/booking/tasks/{$this->task->number}/quality/cancel")->assertSessionHas('status');
        $this->assertSame(WorkflowStatus::Cancelled, $workflow->fresh()->status);
    }

    // --- helpers -------------------------------------------------------------------------------

    /**
     * Plays the Local Runner inside the waiter: claims each signed command and completes it.
     * Checkpoints get deterministic SHAs (sha(1), sha(2)…); tools get the scripted result.
     *
     * @param  array<string, array<string, mixed>>  $results
     * @param  Closure(RunnerCommand): bool|null  $onClaim  true = it handled the command instead of completing it
     */
    private function scriptRunner(array $results, ?Closure $onClaim = null): void
    {
        $checkpoints = 0;
        $test = $this;

        $this->app->instance(RunnerWaiter::class, new class(app(ToolRunService::class), function () use ($results, $onClaim, &$checkpoints, $test) {
            $gateway = app(RunnerGateway::class);
            $signed = $gateway->claim($test->runner->fresh());

            if (! $signed) {
                return;
            }

            $command = RunnerCommand::findOrFail($signed['id']);

            if ($onClaim && $onClaim($command) === true) {
                return;
            }

            if ($command->type->value === 'workspace.checkpoint') {
                $checkpoints++;
                $gateway->complete($command, true, ['sha' => $test->sha($checkpoints), 'changed' => true, 'files' => ['app/Models/Booking.php']]);

                return;
            }

            $key = $command->payload['tool'];
            $gateway->complete($command, true, [
                'tool' => $key,
                'status' => 'passed',
                'exit_code' => 0,
                'duration_ms' => 1200,
                'head_sha' => $test->sha(max(1, $checkpoints)),
                'tests' => null,
                'failures' => [],
                'issues' => [],
                'files_changed' => [],
                'flaky' => [],
                'notes' => [],
                'output_tail' => '',
                ...($results[$key] ?? []),
            ]);
        }) extends RunnerWaiter
        {

            public function __construct(ToolRunService $tools, private readonly Closure $act)
            {
                parent::__construct($tools);
            }

            protected function pause(): void
            {
                ($this->act)();
            }
        });
    }

    public function sha(int $n): string
    {
        return str_pad(dechex($n), 40, 'c', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        $workflow = Workflow::where('type', QualityChecks::WORKFLOW)->latest('id')->first();

        return $workflow->steps()->where('name', QualityReportStep::NAME)->sole()->output;
    }

    /** @param list<array<string, mixed>>|null $tools */
    private function scanWith(?array $tools): void
    {
        $command = app(RunnerGateway::class)->dispatch($this->runner, RunnerCommandType::ProjectScan, ['project_path' => $this->project->connection->local_path], $this->project);
        $this->runnerApi('POST', 'commands/claim');
        $result = $this->scanResult();
        if ($tools !== null) {
            $result['tools'] = $tools;
        }
        app(RunnerGateway::class)->complete($command, true, $result);
    }

    /** @return array<string, mixed> */
    private function tool(string $key, string $kind, string $label, string $command, bool $supportsFix = false): array
    {
        return ['key' => $key, 'kind' => $kind, 'ecosystem' => explode('.', $key)[0] === 'e2e' ? 'js' : explode('.', $key)[0], 'label' => $label, 'command' => $command, 'parser' => 'junit', 'supports_fix' => $supportsFix, 'scoped' => $supportsFix, 'filterable' => $kind === 'test', 'blocked' => null];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, ProjectTool>
     */
    private function seedTools(array $keys): array
    {
        $kinds = ['php.install' => 'install', 'js.install' => 'install', 'php.format' => 'format', 'php.static' => 'static', 'php.test' => 'test', 'js.build' => 'build', 'js.lint' => 'static'];

        return collect($keys)->mapWithKeys(fn (string $key) => [$key => $this->seedTool($key, $kinds[$key], ucfirst(str_replace('.', ' ', $key)), supportsFix: $key === 'php.format')])->all();
    }

    private function seedTool(string $key, string $kind, string $label, bool $enabled = true, ?string $blocked = null, bool $supportsFix = false): ProjectTool
    {
        return ProjectTool::forceCreate([
            'project_id' => $this->project->id,
            'key' => $key,
            'kind' => $kind,
            'ecosystem' => explode('.', $key)[0] === 'e2e' ? 'js' : explode('.', $key)[0],
            'label' => $label,
            'command' => $key,
            'parser' => 'junit',
            'supports_fix' => $supportsFix,
            'scoped' => $supportsFix,
            'enabled' => $enabled,
            'available' => true,
            'blocked' => $blocked,
            'detected_at' => now(),
        ]);
    }
}
