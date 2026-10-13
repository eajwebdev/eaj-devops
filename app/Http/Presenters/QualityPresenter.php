<?php

namespace App\Http\Presenters;

use App\Models\ProjectTool;
use App\Models\Task;
use App\Models\ToolRun;
use App\Models\WorkflowStep;
use App\Tools\QualityChecks;
use App\Tools\Steps\CheckpointWorkspaceStep;
use App\Tools\Steps\QualityReportStep;
use App\Tools\Steps\RunToolStep;

/** The task's latest quality check: each gate's real tool run and the verdict, from persisted rows only. */
final class QualityPresenter
{
    /** @return array<string, mixed> */
    public static function forTask(Task $task): array
    {
        $workflow = app(QualityChecks::class)->latest($task)?->load('steps');
        $tools = ProjectTool::where('project_id', $task->project_id)->get();

        $base = [
            'tools' => [
                'runnable' => $tools->filter->runnable()->count(),
                'total' => $tools->where('available', true)->count(),
            ],
            'workflow' => null,
            'report' => null,
            'steps' => [],
        ];

        if (! $workflow) {
            return $base;
        }

        $steps = $workflow->steps->sortBy('position')->values();
        $runs = ToolRun::where('workflow_id', $workflow->id)->orderBy('id')->get()->groupBy('workflow_step_id')->map->last();
        $labels = $tools->keyBy('key');

        return [
            ...$base,
            'workflow' => [
                'id' => $workflow->id,
                'status' => $workflow->status->value,
                'blocked_reason' => $workflow->blocked_reason?->value,
                'started_at' => $workflow->started_at?->toIso8601String(),
                'finished_at' => $workflow->finished_at?->toIso8601String(),
            ],
            'report' => $steps->firstWhere('name', QualityReportStep::NAME)?->output,
            'steps' => $steps->reject(fn (WorkflowStep $s) => $s->name === QualityReportStep::NAME)->map(function (WorkflowStep $s) use ($runs, $labels) {
                $run = $runs->get($s->id);
                $tool = $labels->get($s->input['tool'] ?? '');

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'status' => $s->status->value,
                    'label' => match ($s->name) {
                        RunToolStep::NAME => $run?->label ?? $tool?->label ?? ($s->input['tool'] ?? 'Tool'),
                        CheckpointWorkspaceStep::NAME => 'Checkpoint '.($s->input['reason'] ?? ''),
                        default => $s->name,
                    },
                    'kind' => $run?->kind->value ?? $tool?->kind->value,
                    'mode' => $s->input['mode'] ?? null,
                    'attempt' => $s->attempt,
                    'error' => $s->last_error,
                    // Why a tool step did not run (switched off, dependency failed).
                    'reason' => $s->name === RunToolStep::NAME ? ($s->output['reason'] ?? null) : null,
                    'checkpoint' => $s->name === CheckpointWorkspaceStep::NAME && $s->output ? [
                        'sha' => $s->output['sha'] ?? null,
                        'changed' => (bool) ($s->output['changed'] ?? false),
                        'files' => (int) ($s->output['files'] ?? 0),
                    ] : null,
                    'run' => $run?->toClientArray(),
                ];
            })->values()->all(),
        ];
    }
}
