<?php

namespace Tests\Feature\Reliability;

use App\Enums\BlockedReason;
use App\Enums\StepStatus;
use App\Enums\WorkflowStatus;
use App\Jobs\PublishOutboxEvents;
use App\Jobs\RunWorkflowStep;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Reliability\Exceptions\ConcurrencyConflictException;
use App\Reliability\Exceptions\InvalidTransitionException;
use App\Workflow\StepResult;
use App\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ScriptedStepHandler;
use Tests\TestCase;

/**
 * Plan §48.11 — every state transition, valid and invalid, plus the chaos
 * scenarios (crash mid-step, lost messages, repeated failures).
 */
class WorkflowEngineTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowEngine $engine;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        ScriptedStepHandler::reset();
        config(['eaj.step_handlers' => [
            'plan' => ScriptedStepHandler::class,
            'build' => ScriptedStepHandler::class,
            'test' => ScriptedStepHandler::class,
        ]]);

        Queue::fake([PublishOutboxEvents::class]);
        $this->engine = app(WorkflowEngine::class);
        $this->project = Project::factory()->create();
    }

    private function start(array $steps = ['plan', 'build', 'test'], array $limits = []): Workflow
    {
        return $this->engine->start(
            $this->project,
            'test',
            array_map(fn ($name) => ['name' => $name], $steps),
            $this->project->organization,
            $this->project,
            $limits,
        );
    }

    private function types(Workflow $workflow): array
    {
        return OutboxEvent::where('project_id', $this->project->id)
            ->where('payload->workflow_id', $workflow->id)
            ->orderBy('sequence')
            ->pluck('type')
            ->all();
    }

    public function test_runs_all_steps_in_order_and_checkpoints_each_one()
    {
        $workflow = $this->start()->refresh();

        $this->assertSame(WorkflowStatus::Completed, $workflow->status);
        $this->assertSame(['plan' => 1, 'build' => 1, 'test' => 1], ScriptedStepHandler::$runs);
        $this->assertSame(3, $workflow->checkpoints()->count());
        $this->assertNotNull($workflow->checkpoints()->first()->commit_sha);
        $this->assertTrue($workflow->steps->every(fn (WorkflowStep $s) => $s->status === StepStatus::Completed));
        $this->assertSame(['step' => 'build', 'run' => 1], $workflow->steps[1]->output);
        $this->assertContains('workflow.step.completed', $this->types($workflow));
    }

    public function test_a_completed_step_is_never_run_again()
    {
        Queue::fake();
        $workflow = $this->start();
        $first = $workflow->steps()->first();

        $this->engine->runStep($first->id);
        $this->engine->runStep($first->id); // duplicate delivery
        $this->engine->runStep($first->id);

        $this->assertSame(1, ScriptedStepHandler::$runs['plan']);
        $this->assertSame(1, $workflow->refresh()->current_step);
    }

    public function test_a_step_that_is_not_current_is_ignored()
    {
        Queue::fake();
        $workflow = $this->start();

        $this->engine->runStep($workflow->steps()->where('position', 2)->value('id'));

        $this->assertSame([], ScriptedStepHandler::$runs);
    }

    public function test_failures_retry_with_backoff_then_succeed()
    {
        ScriptedStepHandler::$failures['build'] = 2;

        $workflow = $this->start()->refresh();

        $this->assertSame(WorkflowStatus::Completed, $workflow->status);
        $this->assertSame(3, ScriptedStepHandler::$runs['build']);
        $this->assertSame(3, $workflow->steps[1]->attempt);
        $this->assertSame(2, collect($this->types($workflow))->filter(fn ($t) => $t === 'workflow.step.retrying')->count());
    }

    public function test_exhausted_attempts_block_the_workflow_and_ask_the_owner()
    {
        ScriptedStepHandler::$failures['build'] = 99;

        $workflow = $this->start()->refresh();

        $this->assertSame(WorkflowStatus::Blocked, $workflow->status);
        $this->assertSame(BlockedReason::LimitReached, $workflow->blocked_reason);
        $this->assertSame(3, ScriptedStepHandler::$runs['build']);
        $this->assertArrayNotHasKey('test', ScriptedStepHandler::$runs);
        $this->assertSame(StepStatus::Failed, $workflow->steps[1]->status);
        $this->assertContains('human.approval.required', $this->types($workflow));
    }

    public function test_missing_handler_blocks_immediately_without_retrying()
    {
        $workflow = $this->start(['plan', 'deploy'])->refresh();

        $this->assertSame(WorkflowStatus::Blocked, $workflow->status);
        $this->assertSame(BlockedReason::NeedsDecision, $workflow->blocked_reason);
        $this->assertSame(1, $workflow->steps[1]->attempt);
    }

    public function test_owner_resume_after_block_grants_fresh_attempts()
    {
        ScriptedStepHandler::$failures['build'] = 3;
        $workflow = $this->start()->refresh();
        $this->assertSame(WorkflowStatus::Blocked, $workflow->status);

        $this->engine->resume($workflow);

        $workflow->refresh();
        $this->assertSame(WorkflowStatus::Completed, $workflow->status);
        $this->assertNull($workflow->blocked_reason);
        $this->assertSame(4, ScriptedStepHandler::$runs['build']);
    }

    public function test_crashed_worker_is_recovered_from_the_last_checkpoint()
    {
        Queue::fake();
        $workflow = $this->start();
        $plan = $workflow->steps()->where('position', 0)->first();
        $this->engine->runStep($plan->id);

        // Simulate a worker that claimed "build" and then died: RUNNING with an old heartbeat.
        $build = $workflow->steps()->where('position', 1)->first();
        $build->forceFill(['status' => StepStatus::Running, 'attempt' => 1, 'heartbeat_at' => now()->subMinutes(10)])->save();

        $result = $this->engine->recover();

        $this->assertSame(1, $result['recovered']);
        $build->refresh();
        $this->assertSame(StepStatus::Pending, $build->status);
        $this->assertSame($workflow->checkpoints()->latest('id')->value('id'), $build->resume_checkpoint_id);
        $this->assertStringContainsString('Stalled', $build->last_error);
        $this->assertContains('workflow.step.recovered', $this->types($workflow));
        Queue::assertPushed(RunWorkflowStep::class, fn ($job) => $job->stepId === $build->id);

        // The redelivered message completes the step.
        $this->engine->runStep($build->id);
        $this->assertSame(StepStatus::Completed, $build->refresh()->status);
    }

    public function test_a_late_result_from_a_recovered_step_is_discarded()
    {
        Queue::fake();
        $workflow = $this->start();
        $plan = $workflow->steps()->first();
        $plan->forceFill(['status' => StepStatus::Running, 'attempt' => 1, 'heartbeat_at' => now()->subMinutes(10)])->save();
        $this->engine->recover();

        $this->engine->completeStep($plan->id, new StepResult(['late' => true]));

        $this->assertSame(StepStatus::Pending, $plan->refresh()->status);
        $this->assertNull($plan->output);
        $this->assertSame(0, $workflow->checkpoints()->count());
    }

    public function test_lost_queue_messages_are_redelivered()
    {
        Queue::fake();
        $workflow = $this->start();
        WorkflowStep::where('workflow_id', $workflow->id)->update(['updated_at' => now()->subMinutes(10)]);
        Queue::fake(); // forget the original dispatch

        $this->assertSame(1, $this->engine->recover()['redelivered']);
        Queue::assertPushed(RunWorkflowStep::class, 1);
    }

    public function test_wall_time_limit_blocks_the_workflow()
    {
        Queue::fake();
        $workflow = $this->start(limits: ['max_workflow_seconds' => 60]);
        Workflow::whereKey($workflow->id)->update(['started_at' => now()->subMinutes(5)]);

        $this->assertSame(1, $this->engine->recover()['timed_out']);
        $this->assertSame(WorkflowStatus::Blocked, $workflow->refresh()->status);
        $this->assertSame(BlockedReason::LimitReached, $workflow->blocked_reason);
    }

    public function test_paused_workflow_keeps_progress_and_resumes_where_it_stopped()
    {
        Queue::fake();
        $workflow = $this->start();
        $this->engine->runStep($workflow->steps()->first()->id);

        $this->engine->pause($workflow);
        $this->engine->runStep($workflow->steps()->where('position', 1)->value('id'));
        $this->assertArrayNotHasKey('build', ScriptedStepHandler::$runs);

        $this->engine->resume($workflow);
        $this->engine->runStep($workflow->steps()->where('position', 1)->value('id'));
        $this->assertSame(1, ScriptedStepHandler::$runs['build']);
    }

    public function test_cancel_preserves_completed_steps_and_cancels_the_rest()
    {
        Queue::fake();
        $workflow = $this->start();
        $this->engine->runStep($workflow->steps()->first()->id);

        $this->engine->cancel($workflow);

        $statuses = $workflow->steps()->pluck('status')->all();
        $this->assertSame([StepStatus::Completed, StepStatus::Cancelled, StepStatus::Cancelled], $statuses);
        $this->assertSame(WorkflowStatus::Cancelled, $workflow->refresh()->status);
    }

    public function test_invalid_transitions_are_rejected()
    {
        $workflow = $this->start()->refresh();
        $this->assertSame(WorkflowStatus::Completed, $workflow->status);

        $this->expectException(InvalidTransitionException::class);
        $this->engine->transition($workflow, WorkflowStatus::Running);
    }

    public function test_stale_versions_cannot_overwrite_newer_state()
    {
        Queue::fake();
        $workflow = $this->start();
        $stale = Workflow::find($workflow->id);

        $this->engine->pause($workflow);

        $this->expectException(ConcurrencyConflictException::class);
        $this->engine->transition($stale, WorkflowStatus::Cancelled);
    }
}
