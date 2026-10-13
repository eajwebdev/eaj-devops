<?php

namespace App\Http\Controllers;

use App\Actions\Workspaces\ManageWorkspaces;
use App\Costs\CostEstimator;
use App\Enums\Permission;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\WorkspaceStatus;
use App\Http\Presenters\AiPlanPresenter;
use App\Http\Presenters\DeliveryPresenter;
use App\Http\Presenters\QualityPresenter;
use App\Http\Presenters\TaskPresenter;
use App\Models\Project;
use App\Models\Task;
use App\Runner\Exceptions\RunnerUnavailableException;
use App\Tasks\TaskException;
use App\Tasks\TaskService;
use App\Workflow\Exceptions\OperationsHaltedException;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Task actions (Plan §66, §95). Every mutation goes through TaskService.
 */
class TaskController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly CostEstimator $costs,
    ) {}

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('create', [Task::class, $project]);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:20000'],
            'acceptance_criteria' => ['nullable', 'array', 'max:30'],
            'acceptance_criteria.*' => ['nullable', 'string', 'max:500'],
            'type' => ['required', Rule::enum(TaskType::class)],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'status' => ['nullable', Rule::in([TaskStatus::Backlog->value, TaskStatus::Ready->value])],
            'token_budget' => ['nullable', 'integer', 'min:100', 'max:100000000'],
        ]);

        $task = $this->tasks->create($project, $request->user(), $data);

        return to_route('tasks.show', [$project, $task->number])->with('status', "{$task->key()} created. Impact analysis is ready below.");
    }

    public function show(Request $request, Project $project, Task $task): Response
    {
        $this->authorize('view', $task);

        $task->setRelation('project', $project);
        $task->load([
            'dependencies:id,project_id,number,title,status',
            'dependents:id,project_id,number,title,status',
            'comments.user:id,name',
            'transitions.user:id,name',
            'creator:id,name',
            'workspace',
            'latestAgentRun.agent.role',
            'latestAgentRun.model',
            'pullRequests.reviews',
            'project.githubRepository',
        ]);
        $task->dependencies->each->setRelation('project', $project);
        $task->dependents->each->setRelation('project', $project);

        $connection = $project->connection;

        $presented = TaskPresenter::detail($task);
        if ($presented['impact']) {
            $presented['impact']['estimated_cost'] = $this->costs->forTask($task);
        }

        return Inertia::render('tasks/show', [
            'project' => $project->only('id', 'key', 'name', 'slug'),
            'task' => $presented,
            'options' => TaskBoardController::options(),
            'candidates' => fn () => $project->tasks()
                ->whereKeyNot($task->id)
                ->whereNotIn('status', [TaskStatus::Cancelled])
                ->orderByDesc('number')
                ->limit(200)
                ->get(['id', 'number', 'title', 'status'])
                ->map(fn (Task $t) => ['number' => $t->number, 'key' => "{$project->key}-{$t->number}", 'title' => $t->title, 'status' => $t->status->value]),
            'aiPlan' => AiPlanPresenter::forTask($task),
            'quality' => QualityPresenter::forTask($task),
            'delivery' => DeliveryPresenter::forTask($task),
            'github' => $project->githubRepository ? [
                'repository' => [
                    'full_name' => $project->githubRepository->full_name,
                    'html_url' => $project->githubRepository->html_url,
                    'default_branch' => $project->githubRepository->default_branch,
                ],
                'workspace' => $task->workspace ? [
                    'branch' => $task->workspace->branch,
                    'reviewed_sha' => $task->workspace->last_checkpoint_sha,
                    'published_sha' => $task->workspace->published_sha,
                    'published_at' => $task->workspace->published_at?->toIso8601String(),
                ] : null,
                'pull_requests' => $task->pullRequests->sortByDesc('id')->map(fn ($pull) => [
                    'id' => $pull->id,
                    'number' => $pull->github_number,
                    'title' => $pull->title,
                    'state' => $pull->state,
                    'draft' => $pull->draft,
                    'head_sha' => $pull->head_sha,
                    'base_sha' => $pull->base_sha,
                    'html_url' => $pull->html_url,
                    'merged_at' => $pull->merged_at?->toIso8601String(),
                    'reviews' => $pull->reviews->map(fn ($review) => [
                        'reviewer' => $review->reviewer_login,
                        'state' => $review->state,
                        'commit_id' => $review->commit_id,
                        'submitted_at' => $review->submitted_at?->toIso8601String(),
                    ])->values(),
                ])->values(),
            ] : null,
            'workspaceReady' => [
                'runner' => (bool) $connection?->runner && ! $connection->runner->revoked_at,
                'runner_online' => (bool) $connection?->runner?->isOnline(),
                'git' => (bool) $connection?->git_enabled,
            ],
            'can' => [
                'manage' => $request->user()->can('manage', $task),
                'approve' => $request->user()->hasPermissionIn($project->organization, Permission::TasksApprove),
            ],
        ]);
    }

    public function update(Request $request, Project $project, Task $task): RedirectResponse
    {
        $this->authorize('manage', $task);

        $data = $request->validate([
            'version' => ['required', 'integer'],
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'acceptance_criteria' => ['sometimes', 'nullable', 'array', 'max:30'],
            'acceptance_criteria.*' => ['nullable', 'string', 'max:500'],
            'type' => ['sometimes', Rule::enum(TaskType::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'token_budget' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:100000000'],
        ]);

        return $this->attempt(fn () => $this->tasks->update($task, $request->user(), $data, (int) $data['version']), 'Task updated.');
    }

    public function transition(Request $request, Project $project, Task $task): RedirectResponse
    {
        $this->authorize('manage', $task);

        $data = $request->validate([
            'to' => ['required', Rule::enum(TaskStatus::class)],
            'from' => ['required', Rule::enum(TaskStatus::class)],
            'reason' => [Rule::requiredIf(in_array($request->input('to'), [TaskStatus::Blocked->value, TaskStatus::ChangesRequested->value], true)), 'nullable', 'string', 'max:2000'],
        ]);

        $to = TaskStatus::from($data['to']);
        $from = TaskStatus::from($data['from']);

        return $this->attempt(function () use ($task, $request, $to, $from, $data) {
            if ($task->status !== $from) {
                throw TaskException::conflict("{$task->key()} is now in {$task->status->label()}. Reload to see the latest.");
            }

            $to === TaskStatus::ChangesRequested
                ? $this->tasks->requestChanges($task, $request->user(), $data['reason'])
                : $this->tasks->transition($task, $to, 'user', $request->user(), $data['reason'] ?? null, $from);
        }, "{$task->key()} moved to {$to->label()}.");
    }

    /**
     * Kanban drop: status change (if any) plus order between neighbours.
     */
    public function move(Request $request, Project $project, Task $task): RedirectResponse
    {
        $this->authorize('manage', $task);

        $data = $request->validate([
            'to' => ['required', Rule::enum(TaskStatus::class)],
            'from' => ['required', Rule::enum(TaskStatus::class)],
            'after_id' => ['nullable', 'integer'],
            'before_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->attempt(fn () => $this->tasks->move(
            $task,
            $request->user(),
            TaskStatus::from($data['to']),
            TaskStatus::from($data['from']),
            $data['after_id'] ?? null,
            $data['before_id'] ?? null,
            $data['reason'] ?? null,
        ));
    }

    public function comment(Request $request, Project $project, Task $task): RedirectResponse
    {
        $this->authorize('manage', $task);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'kind' => ['nullable', 'in:comment,instruction'],
        ]);

        $this->tasks->comment($task, $request->user(), $data['body'], $data['kind'] ?? 'comment');

        return back();
    }

    public function addDependency(Request $request, Project $project, Task $task): RedirectResponse
    {
        $this->authorize('manage', $task);

        $data = $request->validate(['number' => ['required', 'integer']]);
        $dependency = $project->tasks()->where('number', $data['number'])->first();

        if (! $dependency) {
            return back()->withErrors(['dependency' => "No task {$project->key}-{$data['number']} in this project."]);
        }

        return $this->attempt(fn () => $this->tasks->addDependency($task, $dependency, $request->user()), "{$task->key()} now waits for {$dependency->key()}.", 'dependency');
    }

    public function removeDependency(Request $request, Project $project, Task $task, int $dependency): RedirectResponse
    {
        $this->authorize('manage', $task);

        $dep = $project->tasks()->where('number', $dependency)->firstOrFail();
        $this->tasks->removeDependency($task, $dep, $request->user());

        return back();
    }

    public function analyze(Request $request, Project $project, Task $task): RedirectResponse
    {
        $this->authorize('manage', $task);
        $this->tasks->analyze($task->setRelation('project', $project));

        return back()->with('status', 'Impact analysis refreshed.');
    }

    /**
     * Create the task's isolated Git worktree on the runner (Plan §13) so it is
     * ready when the AI team starts (Phase 4).
     */
    public function prepareWorkspace(Request $request, Project $project, Task $task, ManageWorkspaces $workspaces): RedirectResponse
    {
        $this->authorize('manage', $task);

        if ($task->status !== TaskStatus::Ready) {
            return back()->withErrors(['workspace' => 'Move the task to Ready before preparing its workspace.']);
        }

        if ($task->workspace && ! in_array($task->workspace->status, [WorkspaceStatus::Removed, WorkspaceStatus::Failed], true)) {
            return back()->with('status', 'The workspace already exists.');
        }

        try {
            $workspaces->create($project->load('connection.runner'), $task->key(), $task->title, $request->user(), null, $task);
        } catch (RunnerUnavailableException|OperationsHaltedException $e) {
            return back()->withErrors(['workspace' => $e->getMessage()]);
        }

        return back()->with('status', "Workspace for {$task->key()} queued on the runner.");
    }

    private function attempt(Closure $action, ?string $success = null, string $errorKey = 'task'): RedirectResponse
    {
        try {
            $action();
        } catch (TaskException $e) {
            return back()->withErrors([$errorKey => $e->getMessage()])->with('conflict', $e->conflict);
        }

        return $success ? back()->with('status', $success) : back();
    }
}
