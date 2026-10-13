<?php

namespace App\Delivery\Steps;

use App\Delivery\DeliveryWorkflow;
use App\Enums\StepStatus;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\ToolRun;
use App\Models\WorkflowStep;
use App\Tools\Steps\QualityReportStep;
use App\Workflow\Contracts\StepHandler;
use App\Workflow\Exceptions\NonRetryableStepException;
use App\Workflow\Exceptions\StepLimitReachedException;
use App\Workflow\StepContext;
use App\Workflow\StepResult;

/**
 * Workflow step "delivery.after_checks" (§48.10 "Tests fail → route back to
 * the responsible specialist"): passing gates open the review round; failing
 * gates start a fix cycle for the developers, at most max_fix_cycles times,
 * then the task stops and asks the owner.
 */
class AfterChecksStep implements StepHandler
{
    public const NAME = 'delivery.after_checks';

    private const LIMIT_MESSAGE = 'Quality gates still failing after';

    public function handle(WorkflowStep $step, StepContext $context): StepResult
    {
        $workflow = $step->workflow()->with('subject.project')->firstOrFail();
        $task = $workflow->subject;
        $report = $workflow->steps()->where('name', QualityReportStep::NAME)->where('position', '<', $step->position)
            ->where('status', StepStatus::Completed)->reorder('position', 'desc')->first()?->output;

        if (! $task instanceof Task || ! $report) {
            throw new NonRetryableStepException('There is no quality report to act on.');
        }

        $developers = (array) ($step->input['developers'] ?? []);
        $reviewers = (array) ($step->input['reviewers'] ?? []);

        if ($report['passed'] ?? false) {
            $reviewCycle = $workflow->steps()->where('name', ReviewDecisionStep::NAME)->where('status', StepStatus::Completed)->count();

            return new StepResult(
                output: ['decision' => 'review', 'head_sha' => $report['head_sha'] ?? null],
                append: DeliveryWorkflow::reviewRound($reviewers, $developers, $reviewCycle),
            );
        }

        $failing = collect($report['gates'] ?? [])->whereIn('status', ['failed', 'not_run'])->pluck('label')->join(', ')
            ?: ($report['note'] ?? 'the results did not describe one commit');
        $used = $workflow->steps()->where('name', self::NAME)->where('position', '<', $step->position)
            ->where('status', StepStatus::Completed)->get()->where('output.decision', 'fix')->count();
        $limit = $workflow->limit('max_fix_cycles');

        // The owner resuming after the limit is their decision to allow one more cycle (§2.6).
        $grantedByOwner = str_starts_with((string) $step->last_error, self::LIMIT_MESSAGE);

        if ($used >= $limit && ! $grantedByOwner) {
            throw new StepLimitReachedException(self::LIMIT_MESSAGE." {$used} fix cycles ({$failing}). Needs your decision: give the developers an instruction and resume, or cancel.");
        }

        $failingFiles = ToolRun::whereIn('id', collect($report['gates'] ?? [])->flatMap(fn ($g) => collect($g['runs'] ?? [])->pluck('tool_run_id'))->filter())
            ->get()
            ->flatMap(fn (ToolRun $r) => [...collect($r->failures ?? [])->pluck('file'), ...collect($r->issues ?? [])->pluck('file')]);
        $fixers = DeliveryWorkflow::responsible($workflow, $developers, $failingFiles);

        return new StepResult(
            output: ['decision' => 'fix', 'cycle' => $used + 1, 'failing' => $failing, 'developers' => $fixers],
            append: [
                DeliveryWorkflow::transition(TaskStatus::InProgress, ($used < $limit ? 'Fix cycle '.($used + 1)." of {$limit}" : 'Extra fix cycle '.($used + 1).", allowed by the owner after the limit of {$limit}").": {$failing} failed."),
                ...DeliveryWorkflow::implementRound($fixers, 'fix', $used + 1),
                ...DeliveryWorkflow::checksRound($task->project, $developers, $reviewers),
            ],
        );
    }
}
