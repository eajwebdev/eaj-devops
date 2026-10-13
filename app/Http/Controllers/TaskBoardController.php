<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Presenters\TaskPresenter;
use App\Models\Project;
use App\Models\Task;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kanban board (Plan §30) for one project of the current organization.
 */
class TaskBoardController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current): Response
    {
        $organization = $current->get();
        $this->authorize('viewAny', [Project::class, $organization]);

        $projects = $organization->projects()->where('status', 'active')->orderBy('name')->get(['id', 'key', 'name', 'slug', 'organization_id']);
        $project = $projects->firstWhere('slug', $request->query('project')) ?? $projects->first();

        $tasks = $project
            ? Task::query()
                ->where('project_id', $project->id)
                ->where(fn ($q) => $q->where('status', '!=', TaskStatus::Done)->orWhere('completed_at', '>=', now()->subDays(14)))
                ->where('status', '!=', TaskStatus::Cancelled)
                ->with(['dependencies:id,project_id,number,status', 'workspace', 'latestAgentRun.agent.role', 'latestAgentRun.model'])
                ->withCount('comments')
                ->orderBy('position')
                ->limit(1000)
                ->get()
                ->each(fn (Task $t) => $t->setRelation('project', $project))
            : collect();

        return Inertia::render('tasks/board', [
            'projects' => $projects->map->only('id', 'key', 'name', 'slug')->values(),
            'project' => $project?->only('id', 'key', 'name', 'slug'),
            'columns' => TaskPresenter::columns(),
            'tasks' => $tasks->map(fn (Task $t) => TaskPresenter::card($t))->values(),
            'options' => self::options(),
            'can' => [
                'manage' => $project !== null && $request->user()->hasPermissionIn($organization, Permission::TasksManage),
                'approve' => $request->user()->hasPermissionIn($organization, Permission::TasksApprove),
            ],
        ]);
    }

    /**
     * @return array{types: list<array{value: string, label: string}>, priorities: list<array{value: string, label: string}>}
     */
    public static function options(): array
    {
        return [
            'types' => array_map(fn (TaskType $t) => ['value' => $t->value, 'label' => $t->label()], TaskType::cases()),
            'priorities' => array_map(fn (TaskPriority $p) => ['value' => $p->value, 'label' => $p->label()], TaskPriority::cases()),
        ];
    }
}
