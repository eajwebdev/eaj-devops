<?php

namespace App\Http\Presenters;

use App\Delivery\DeliveryWorkflow;
use App\Delivery\Steps\AfterChecksStep;
use App\Delivery\Steps\ReviewDecisionStep;
use App\Delivery\Steps\TaskTransitionStep;
use App\Enums\StepStatus;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ChangeSet;
use App\Models\Review;
use App\Models\Task;
use App\Models\ToolRun;
use App\Models\WorkflowStep;
use App\Tools\Steps\CheckpointWorkspaceStep;
use App\Tools\Steps\QualityReportStep;
use App\Tools\Steps\RunToolStep;
use Illuminate\Support\Str;

/** The task's latest AI delivery: every step with what really happened in it, grouped into rounds. */
final class DeliveryPresenter
{
    private const DIFF_PREVIEW = 60_000;

    /** @return array<string, mixed>|null */
    public static function forTask(Task $task): ?array
    {
        $workflow = app(DeliveryWorkflow::class)->latest($task)?->load('steps');

        if (! $workflow) {
            return null;
        }

        $steps = $workflow->steps->sortBy('position')->values();
        $changeSets = ChangeSet::where('workflow_id', $workflow->id)->get()->keyBy('workflow_step_id');
        $reviews = Review::where('workflow_id', $workflow->id)->get()->keyBy('workflow_step_id');
        $toolRuns = ToolRun::where('workflow_id', $workflow->id)->orderBy('id')->get()->groupBy('workflow_step_id')->map->last();
        $runs = AgentRun::whereIn('workflow_step_id', $steps->pluck('id'))->with('model:id,display_name')->orderBy('id')->get()->groupBy('workflow_step_id')->map->last();
        $agents = Agent::where('organization_id', $task->organization_id)->with('role')->get()->keyBy(fn (Agent $a) => $a->role->key);

        $round = 0;
        $items = $steps->map(function (WorkflowStep $s) use (&$round, $changeSets, $reviews, $toolRuns, $runs, $agents) {
            if ($s->name === TaskTransitionStep::NAME && ($s->input['to'] ?? null) === TaskStatus::InProgress->value) {
                $round++;
            }

            $run = $runs->get($s->id);
            $role = $s->input['role'] ?? null;

            return [
                'id' => $s->id,
                'round' => $round,
                'name' => $s->name,
                'status' => $s->status->value,
                'attempt' => $s->attempt,
                'error' => $s->status === StepStatus::Failed || $s->status === StepStatus::Pending ? $s->last_error : null,
                'input' => array_intersect_key($s->input ?? [], array_flip(['to', 'reason', 'role', 'mode', 'gate', 'tool', 'cycle'])),
                'output' => in_array($s->name, [AfterChecksStep::NAME, ReviewDecisionStep::NAME, QualityReportStep::NAME, CheckpointWorkspaceStep::NAME, TaskTransitionStep::NAME, RunToolStep::NAME], true) ? $s->output : null,
                'agent' => $role ? $agents->get($role)?->name : null,
                'agent_run' => $run ? ['status' => $run->status->value, 'model' => $run->model?->display_name, 'tokens' => $run->total_tokens] : null,
                'change_set' => ($c = $changeSets->get($s->id)) ? [...$c->toClientArray(), 'diff' => $c->diff !== null ? Str::limit($c->diff, self::DIFF_PREVIEW, "\n… (diff continues)") : null] : null,
                'review' => $reviews->get($s->id)?->toClientArray(),
                'tool_run' => $s->name === RunToolStep::NAME ? $toolRuns->get($s->id)?->toClientArray() : null,
            ];
        });

        $failed = $steps->first(fn (WorkflowStep $s) => $s->status === StepStatus::Failed);

        return [
            'workflow' => [
                'id' => $workflow->id,
                'status' => $workflow->status->value,
                'blocked_reason' => $workflow->blocked_reason?->value,
                'stopped_because' => $failed?->last_error,
                'started_at' => $workflow->started_at?->toIso8601String(),
                'finished_at' => $workflow->finished_at?->toIso8601String(),
            ],
            'totals' => [
                'files' => ChangeSet::where('workflow_id', $workflow->id)->get()->flatMap(fn (ChangeSet $c) => collect($c->files ?? [])->pluck('path'))->unique()->count(),
                'additions' => (int) $changeSets->sum('additions'),
                'deletions' => (int) $changeSets->sum('deletions'),
                'tokens' => (int) AgentRun::whereIn('workflow_step_id', $steps->pluck('id'))->sum('total_tokens'),
            ],
            'steps' => $items->all(),
        ];
    }
}
