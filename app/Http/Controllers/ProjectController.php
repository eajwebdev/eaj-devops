<?php

namespace App\Http\Controllers;

use App\Actions\Projects\RequestRunnerWork;
use App\Enums\ProjectConnectionStatus;
use App\Enums\ProjectConnectionType;
use App\Enums\WorkflowStatus;
use App\Enums\WorkspaceStatus;
use App\Http\Presenters\ProjectPresenter;
use App\Http\Presenters\RunnerPresenter;
use App\Http\Requests\StoreProjectRequest;
use App\Models\LocalRunner;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\ProjectConnection;
use App\Models\RepositoryWorkspace;
use App\Models\RunnerCommand;
use App\Models\Workflow;
use App\Reliability\AuditLogger;
use App\Reliability\EventRecorder;
use App\Runner\Exceptions\RunnerUnavailableException;
use App\Support\CurrentOrganization;
use App\Workflow\Exceptions\OperationsHaltedException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $current,
        private readonly EventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): Response
    {
        $organization = $this->current->get();
        $this->authorize('viewAny', [Project::class, $organization]);

        $projects = $organization->projects()
            ->with('connection')
            ->orderBy('name')
            ->get();

        return Inertia::render('projects/index', [
            'projects' => $projects->map(fn (Project $p) => ProjectPresenter::make($p)),
            'can' => ['create' => request()->user()->can('create', [Project::class, $organization])],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', [Project::class, $this->current->get()]);

        return Inertia::render('projects/create', [
            'connectionTypes' => collect(ProjectConnectionType::cases())->map(fn (ProjectConnectionType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'available' => $t->isAvailable(),
            ]),
            'runners' => $this->runnersFor($this->current->get()),
        ]);
    }

    public function store(StoreProjectRequest $request, RequestRunnerWork $work): RedirectResponse
    {
        $organization = $this->current->get();
        $data = $request->validated();

        $project = DB::transaction(function () use ($organization, $data, $request) {
            $project = new Project([
                'key' => $data['key'],
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($organization->projects(), $data['name']),
                'description' => $data['description'] ?? null,
            ]);
            $project->organization()->associate($organization);
            $project->forceFill(['created_by' => $request->user()->getKey()])->save();

            $connection = new ProjectConnection([
                'type' => $data['connection_type'],
                'local_path' => $data['local_path'] ?? null,
            ]);
            $connection->forceFill([
                'status' => ProjectConnectionStatus::PendingScan,
                'local_runner_id' => $data['local_runner_id'] ?? null,
            ]);
            $project->connection()->save($connection);

            $this->events->record('project.created', [
                'project' => ['id' => $project->id, 'key' => $project->key, 'name' => $project->name],
                'connection_type' => $data['connection_type'],
            ], $project);

            $this->audit->log('project.created', $request->user(), $organization, $project, $project, [
                'connection_type' => $data['connection_type'],
            ]);

            return $project;
        });

        // Plan §12: Choose folder → Scan → Detect stack → Profile → Code index → Ready.
        $scanned = false;
        if ($project->connection->local_runner_id) {
            try {
                $work->scan($project->load('connection.runner'), $request->user());
                $scanned = true;
            } catch (RunnerUnavailableException|OperationsHaltedException) {
                // The project exists; the page explains how to scan it.
            }
        }

        return to_route('projects.show', $project)->with('status', $scanned
            ? "Project {$project->name} created. Scan queued on the runner."
            : "Project {$project->name} created.");
    }

    public function show(Project $project): Response
    {
        $this->authorize('view', $project);
        $project->load('connection.runner');

        $events = OutboxEvent::query()
            ->where('stream', $project->eventStream())
            ->with('actor:id,name')
            ->orderByDesc('sequence')
            ->limit(30)
            ->get();

        $workflowCounts = Workflow::query()
            ->where('project_id', $project->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return Inertia::render('projects/show', [
            'project' => ProjectPresenter::make($project),
            'events' => $events->reverse()->values()->map->toClientPayload(),
            'stats' => [
                'active_workflows' => (int) ($workflowCounts[WorkflowStatus::Running->value] ?? 0),
                'blocked_workflows' => (int) ($workflowCounts[WorkflowStatus::Blocked->value] ?? 0),
                'paused_workflows' => (int) ($workflowCounts[WorkflowStatus::Paused->value] ?? 0),
            ],
            'runner' => $project->connection?->runner ? RunnerPresenter::make($project->connection->runner) : null,
            'runners' => fn () => $this->runnersFor($project->organization),
            'commands' => RunnerCommand::query()
                ->where('project_id', $project->id)
                ->latest()
                ->limit(8)
                ->get()
                ->map->toClientArray(),
            'workspaces' => RepositoryWorkspace::query()
                ->where('project_id', $project->id)
                ->where('status', '!=', WorkspaceStatus::Removed)
                ->latest()
                ->get()
                ->map->toClientArray(),
            'can' => [
                'update' => request()->user()->can('update', $project),
                'delete' => request()->user()->can('delete', $project),
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runnersFor(Organization $organization): array
    {
        return LocalRunner::query()
            ->active()
            ->where('organization_id', $organization->id)
            ->orderByDesc('last_heartbeat_at')
            ->get()
            ->map(fn (LocalRunner $r) => RunnerPresenter::make($r))
            ->all();
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $request->validate(['confirm_key' => ['required', 'in:'.$project->key]], [
            'confirm_key.in' => "Type {$project->key} to confirm.",
        ]);

        DB::transaction(function () use ($project, $request) {
            // Removes the Studio record only — the project folder on disk is never touched.
            $this->audit->log('project.deleted', $request->user(), $project->organization, metadata: [
                'project_id' => $project->id,
                'key' => $project->key,
                'name' => $project->name,
            ]);

            $this->events->record('project.deleted', [
                'project' => ['id' => $project->id, 'key' => $project->key, 'name' => $project->name],
            ], organization: $project->organization);

            $project->delete();
        });

        return to_route('projects.index')->with('status', "Project {$project->name} removed from the Studio. Files on disk were not touched.");
    }

    /**
     * @param  HasMany<Project, Organization>  $query
     */
    private function uniqueSlug(HasMany $query, string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $i = 2;

        while ((clone $query)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
