<?php

namespace App\AI;

use App\AI\Steps\FinishPlanningStep;
use App\AI\Steps\RunSpecialistStep;
use App\Enums\TaskStatus;
use App\Enums\WorkflowStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Reliability\AuditLogger;
use App\Tasks\TaskService;
use App\Workflow\WorkflowEngine;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Turns a Ready task into structured AI work (§2.3, §66): the impact analysis
 * picks the specialists, each runs as a durable workflow step, and the task
 * leaves Planning as soon as planning ends — however it ends.
 */
class Orchestrator
{
    public const WORKFLOW = 'task.ai_planning';

    public function __construct(
        private readonly SpecialistRouter $router,
        private readonly WorkflowEngine $engine,
        private readonly TaskService $tasks,
        private readonly AuditLogger $audit,
    ) {}

    public function plan(Task $task, User $user): Workflow
    {
        $task->loadMissing('project.organization');

        if ($task->status !== TaskStatus::Ready) {
            throw new DomainException("Move {$task->key()} to Ready before handing it to the AI team.");
        }

        if (! $task->impact) {
            $this->tasks->analyze($task);
            $task->refresh();
        }

        $route = $this->router->route($task);
        if ($route['activate'] === []) {
            throw new DomainException('No specialist can plan this task. '.collect($route['skipped'])->pluck('reason')->unique()->join(' '));
        }

        return DB::transaction(function () use ($task, $user, $route) {
            // A planning run that was blocked earlier is superseded by this one.
            Workflow::where('subject_type', $task->getMorphClass())
                ->where('subject_id', $task->id)
                ->where('type', self::WORKFLOW)
                ->whereIn('status', [WorkflowStatus::Pending, WorkflowStatus::Running, WorkflowStatus::Paused, WorkflowStatus::Blocked])
                ->get()
                ->each(fn (Workflow $old) => $this->engine->cancel($old, 'Superseded by a new AI plan'));

            $roles = collect($route['activate'])->pluck('role');
            $this->tasks->transition($task, TaskStatus::Planning, 'system', $user, "AI planning started by {$user->name}: ".$roles->join(', ').'.', TaskStatus::Ready);

            $steps = collect($route['activate'])
                ->map(fn (array $specialist) => ['name' => RunSpecialistStep::NAME, 'input' => [
                    'role' => $specialist['role'],
                    'purpose' => SpecialistRouter::PURPOSE,
                    'reason' => $specialist['reason'],
                ]])
                ->push(['name' => FinishPlanningStep::NAME, 'input' => ['skipped' => $route['skipped']]])
                ->all();

            $workflow = $this->engine->start($task, self::WORKFLOW, $steps, $task->project->organization, $task->project);

            $this->audit->log('ai.orchestrated', $user, $task->project->organization, $task->project, $task, [
                'workflow_id' => $workflow->id,
                'specialists' => $roles->all(),
                'skipped' => $route['skipped'],
            ]);

            return $workflow;
        });
    }
}
