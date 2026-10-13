<?php

namespace App\Tools\Steps;

use App\Enums\StepStatus;
use App\Enums\ToolKind;
use App\Models\ProjectTool;
use App\Models\Task;
use App\Models\ToolRun;
use App\Models\WorkflowStep;
use App\Reliability\EventRecorder;
use App\Workflow\Contracts\StepHandler;
use App\Workflow\Exceptions\NonRetryableStepException;
use App\Workflow\StepContext;
use App\Workflow\StepResult;

/**
 * Workflow step "quality.report": the gate verdict from parsed tool results
 * only (Plan §48.8 "the AI's claim of tests passed is never trusted"), bound
 * to the commit the tools checked (§48.9).
 */
class QualityReportStep implements StepHandler
{
    public const NAME = 'quality.report';

    public function __construct(private readonly EventRecorder $events) {}

    public function handle(WorkflowStep $step, StepContext $context): StepResult
    {
        $workflow = $step->workflow()->with('subject')->firstOrFail();
        $task = $workflow->subject;

        if (! $task instanceof Task) {
            throw new NonRetryableStepException('This quality check has no task.');
        }

        // A delivery runs the gates once per fix cycle: judge only this round (after the previous report).
        $since = $workflow->steps()->where('name', self::NAME)->where('position', '<', $step->position)->max('position') ?? -1;
        $completed = $workflow->steps()->where('status', StepStatus::Completed)->where('position', '>', $since)->where('position', '<', $step->position)->orderBy('position')->get();
        $runs = ToolRun::whereIn('id', $completed->where('name', RunToolStep::NAME)->pluck('output.tool_run_id')->filter())->get()->keyBy('id');
        $kinds = ProjectTool::where('project_id', $task->project_id)->pluck('kind', 'key');

        // Every gate the workflow tried, including those that could not run.
        $results = $completed->where('name', RunToolStep::NAME)->map(function (WorkflowStep $s) use ($runs, $kinds) {
            $run = $runs->get($s->output['tool_run_id'] ?? 0);
            $kind = $run?->kind ?? $kinds->get($s->input['tool'] ?? '');

            return [
                'kind' => $kind instanceof ToolKind ? $kind : ToolKind::tryFrom((string) $kind),
                'run' => $run,
                'reason' => $s->output['reason'] ?? null,
                'tool' => $s->input['tool'] ?? null,
            ];
        })->filter(fn (array $r) => $r['kind']?->isGate());

        $gates = collect(ToolKind::cases())->filter->isGate()->map(function (ToolKind $kind) use ($results) {
            $items = $results->filter(fn (array $r) => $r['kind'] === $kind)->values();

            if ($items->isEmpty()) {
                return ['kind' => $kind->value, 'label' => $kind->label(), 'status' => 'not_configured', 'runs' => []];
            }

            $passed = $items->every(fn (array $r) => $r['run']?->status->passesGate());

            return [
                'kind' => $kind->value,
                'label' => $kind->label(),
                'status' => $passed ? 'passed' : ($items->contains(fn (array $r) => ! $r['run']) ? 'not_run' : 'failed'),
                'runs' => $items->map(fn (array $r) => [
                    'tool' => $r['run']?->tool_key ?? $r['tool'],
                    'tool_run_id' => $r['run']?->id,
                    'status' => $r['run']?->status->value ?? 'not_run',
                    'summary' => $r['run']?->summary() ?? $r['reason'],
                ])->all(),
            ];
        })->values();

        // Gates run after the last checkpoint must all have checked the same commit; a formatter fix runs before it.
        $checks = $results->map(fn (array $r) => $r['run'])->filter(fn (?ToolRun $r) => $r && $r->mode === 'check');
        $checkHeads = $checks->filter(fn (ToolRun $r) => $r->head_sha)->pluck('head_sha')->unique();
        $checkpoint = $completed->where('name', CheckpointWorkspaceStep::NAME)->last()?->output['sha'] ?? null;
        $head = $checkHeads->last() ?? $checkpoint;
        // A check that rewrote files (e.g. a lint script with --fix) left later tools checking uncommitted code.
        $rewriters = $checks->filter(fn (ToolRun $r) => $r->kind !== ToolKind::Install && ! empty($r->files_changed))->pluck('label');
        $consistent = $checkHeads->count() <= 1 && $rewriters->isEmpty();

        $ran = $gates->where('status', '!=', 'not_configured');
        $passed = $consistent && $ran->isNotEmpty() && $ran->every(fn (array $g) => $g['status'] === 'passed');

        $report = [
            'passed' => $passed,
            'head_sha' => $head,
            'consistent' => $consistent,
            'gates' => $gates->all(),
            'note' => match (true) {
                $consistent => null,
                $rewriters->isNotEmpty() => $rewriters->join(', ').' changed files while checking, so later results do not describe one commit. Run the checks again: the changes are checkpointed first.',
                default => 'The workspace changed while checks were running, so the results do not describe one commit. Run the checks again.',
            },
        ];

        $this->events->record('quality.checks.completed', [
            'workflow_id' => $workflow->id,
            'task_id' => $task->id,
            'task' => $task->key(),
            'passed' => $passed,
            'head_sha' => $head,
            'gates' => $gates->map(fn (array $g) => ['kind' => $g['kind'], 'label' => $g['label'], 'status' => $g['status']])->all(),
        ], $workflow->project);

        return new StepResult(output: $report);
    }
}
