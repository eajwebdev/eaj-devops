<?php

namespace App\Delivery\Steps;

use App\Delivery\DeliveryContext;
use App\Delivery\DeliveryWorkflow;
use App\Enums\StepStatus;
use App\Enums\TaskStatus;
use App\Models\Review;
use App\Models\Task;
use App\Models\WorkflowStep;
use App\Reliability\EventRecorder;
use App\Tasks\TaskService;
use App\Tasks\TaskStateMachine;
use App\Workflow\Contracts\StepHandler;
use App\Workflow\Exceptions\NonRetryableStepException;
use App\Workflow\Exceptions\StepLimitReachedException;
use App\Workflow\StepContext;
use App\Workflow\StepResult;
use Illuminate\Support\Facades\DB;

/**
 * Workflow step "review.decide" (§33, §87): every review gate passed on the
 * same commit → Release Ready, where a person approves (§56). Otherwise the
 * task goes to Changes Requested and back to the developers with every
 * blocking finding, at most max_review_cycles times.
 */
class ReviewDecisionStep implements StepHandler
{
    public const NAME = 'review.decide';

    private const LIMIT_MESSAGE = 'Reviews still failing after';

    public function __construct(
        private readonly TaskService $tasks,
        private readonly EventRecorder $events,
    ) {}

    public function handle(WorkflowStep $step, StepContext $context): StepResult
    {
        $workflow = $step->workflow()->with('subject.project')->firstOrFail();
        $task = $workflow->subject;

        if (! $task instanceof Task) {
            throw new NonRetryableStepException('This decision has no task.');
        }

        $developers = (array) ($step->input['developers'] ?? []);
        $reviewers = (array) ($step->input['reviewers'] ?? []);
        $reviews = Review::whereIn('workflow_step_id', DeliveryContext::reviewSteps($workflow, $step->position)->pluck('id'))->get()->keyBy('gate');

        $missing = array_diff($reviewers, $reviews->keys()->all());
        if ($missing !== []) {
            throw new NonRetryableStepException('Review results are missing for: '.implode(', ', $missing).'.');
        }

        // §48.9: every gate must have judged the same commit.
        $heads = $reviews->pluck('head_sha')->filter()->unique();
        $failed = $reviews->reject->passed;
        $passed = $failed->isEmpty() && $heads->count() <= 1;

        if ($passed) {
            $this->move($task, TaskStatus::ReleaseReady, 'All review gates passed: '.$reviews->map(fn (Review $r) => "{$r->gate} {$r->score}/100")->join(', ').'. Waiting for your approval.');

            $this->events->record('delivery.release_ready', [
                'task_id' => $task->id,
                'task' => ['key' => $task->key(), 'title' => $task->title],
                'workflow_id' => $workflow->id,
                'head_sha' => $heads->first(),
                'scores' => $reviews->map(fn (Review $r) => $r->score)->all(),
            ], $task->project);

            return new StepResult(output: ['decision' => 'release_ready', 'head_sha' => $heads->first()]);
        }

        $why = $failed->map(fn (Review $r) => "{$r->gate} review ".($r->blocking ? count($r->blocking).' blocking finding(s)' : "score {$r->score} < {$r->minimum_score}"))->join('; ')
            ?: 'the reviews judged different commits';
        $used = $workflow->steps()->where('name', self::NAME)->where('position', '<', $step->position)
            ->where('status', StepStatus::Completed)->get()->where('output.decision', 'changes')->count();
        $limit = $workflow->limit('max_review_cycles');
        $grantedByOwner = str_starts_with((string) $step->last_error, self::LIMIT_MESSAGE);

        if ($used >= $limit && ! $grantedByOwner) {
            throw new StepLimitReachedException(self::LIMIT_MESSAGE." {$used} review cycles ({$why}). Needs your decision: request specific changes and resume, or cancel.");
        }

        $this->move($task, TaskStatus::ChangesRequested, "Changes requested by the AI reviewers: {$why}.");
        $fixers = DeliveryWorkflow::responsible($workflow, $developers, $failed->flatMap(fn (Review $r) => collect($r->issues ?? [])->pluck('file')));

        return new StepResult(
            output: ['decision' => 'changes', 'cycle' => $used + 1, 'why' => $why, 'developers' => $fixers],
            append: [
                DeliveryWorkflow::transition(TaskStatus::InProgress, ($used < $limit ? 'Review cycle '.($used + 1)." of {$limit}" : 'Extra review cycle '.($used + 1).', allowed by the owner').': addressing the review findings.'),
                ...DeliveryWorkflow::implementRound($fixers, 'changes', $used + 1),
                ...DeliveryWorkflow::checksRound($task->project, $developers, $reviewers),
            ],
        );
    }

    /** Idempotent: a retried step finds the task already moved. */
    private function move(Task $task, TaskStatus $to, string $reason): void
    {
        DB::transaction(function () use ($task, $to, $reason) {
            $task->refresh();

            if ($task->status === $to) {
                return;
            }

            if (! TaskStateMachine::exists($task->status, $to)) {
                throw new NonRetryableStepException("{$task->key()} is now {$task->status->label()} (moved by a person); the AI delivery cannot move it to {$to->label()}.");
            }

            $this->tasks->transition($task, $to, 'system', null, $reason, $task->status);
        });
    }
}
