<?php

namespace App\Http\Presenters;

use App\AI\Orchestrator;
use App\AI\Steps\FinishPlanningStep;
use App\AI\Steps\RunSpecialistStep;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Task;
use App\Models\Workflow;
use App\Models\WorkflowStep;

/** The task's latest AI plan: every specialist, its true run state and its validated result. */
final class AiPlanPresenter
{
    /** @return array<string, mixed>|null */
    public static function forTask(Task $task): ?array
    {
        $workflow = Workflow::where('subject_type', $task->getMorphClass())
            ->where('subject_id', $task->id)
            ->where('type', Orchestrator::WORKFLOW)
            ->latest('id')
            ->with('steps')
            ->first();

        if (! $workflow) {
            return null;
        }

        $steps = $workflow->steps->sortBy('position');
        $specialists = $steps->where('name', RunSpecialistStep::NAME);
        $agents = Agent::where('organization_id', $task->organization_id)->with('role')->get()->keyBy(fn (Agent $a) => $a->role->key);
        $runs = AgentRun::whereIn('workflow_step_id', $specialists->pluck('id'))
            ->with('model:id,display_name')
            ->orderBy('id')
            ->get()
            ->groupBy('workflow_step_id')
            ->map->last();

        return [
            'status' => $workflow->status->value,
            'started_at' => $workflow->started_at?->toIso8601String(),
            'finished_at' => $workflow->finished_at?->toIso8601String(),
            'skipped' => $steps->firstWhere('name', FinishPlanningStep::NAME)?->input['skipped'] ?? [],
            'specialists' => $specialists->map(function (WorkflowStep $step) use ($agents, $runs) {
                $role = (string) ($step->input['role'] ?? '');
                $run = $runs->get($step->id);
                $result = $run?->output['result'] ?? null;

                return [
                    'role' => $role,
                    'name' => $agents->get($role)?->name ?? $role,
                    'reason' => (string) ($step->input['reason'] ?? ''),
                    'step_status' => $step->status->value,
                    'error' => $step->last_error,
                    'run' => $run ? [
                        'status' => $run->status->value,
                        'model' => $run->model?->display_name,
                        'tokens' => (int) $run->total_tokens,
                        'attempt' => $run->attempt,
                        'prompt_version' => $run->output['prompt_version'] ?? null,
                    ] : null,
                    'result' => $result ? [
                        'status' => $result['status'] ?? null,
                        'summary' => (string) ($result['summary'] ?? ''),
                        'warnings' => array_values((array) ($result['warnings'] ?? [])),
                    ] : null,
                ];
            })->values()->all(),
        ];
    }
}
