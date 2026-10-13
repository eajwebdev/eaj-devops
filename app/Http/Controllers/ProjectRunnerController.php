<?php

namespace App\Http\Controllers;

use App\Actions\Projects\RequestRunnerWork;
use App\Models\LocalRunner;
use App\Models\Project;
use App\Models\ProjectIndexSymbol;
use App\Reliability\AuditLogger;
use App\Runner\Exceptions\RunnerUnavailableException;
use App\Workflow\Exceptions\OperationsHaltedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Local Project Engine actions on a project page (Plan Phase 2).
 */
class ProjectRunnerController extends Controller
{
    public function __construct(private readonly RequestRunnerWork $work) {}

    public function assignRunner(Request $request, Project $project, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate(['local_runner_id' => ['required', 'integer']]);
        $runner = LocalRunner::query()->active()->where('organization_id', $project->organization_id)->find($data['local_runner_id']);

        if (! $runner) {
            throw ValidationException::withMessages(['local_runner_id' => 'Choose an active runner from this organization.']);
        }

        $project->connection->forceFill(['local_runner_id' => $runner->id])->save();
        $audit->log('project.runner.assigned', $request->user(), project: $project, subject: $project, metadata: ['runner_id' => $runner->id]);

        return $this->run(fn () => $this->work->scan($project, $request->user()), "Runner assigned. Scan queued on {$runner->name}.");
    }

    public function scan(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        return $this->run(fn () => $this->work->scan($project, $request->user()), 'Scan queued.');
    }

    public function index(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        return $this->run(fn () => $this->work->rebuildIndex($project, $request->user()), 'Code index rebuild queued.');
    }

    public function initializeGit(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $request->validate(['confirm' => ['accepted']]);

        return $this->run(fn () => $this->work->initializeGit($project, $request->user()), 'Git initialization queued on the runner.');
    }

    /**
     * Search the code index (Plan §41): symbols by name, optionally by kind.
     */
    public function search(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'kind' => ['nullable', 'string', 'max:24'],
        ]);

        if (! $project->index_build_id) {
            return response()->json(['data' => []]);
        }

        $q = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['q']);

        $symbols = ProjectIndexSymbol::query()
            ->where('project_id', $project->id)
            ->where('build_id', $project->index_build_id)
            ->when($data['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->where('name', 'like', "%{$q}%")
            ->with('file:id,path')
            ->orderByRaw('case when name = ? then 0 when name like ? then 1 else 2 end', [$data['q'], "{$q}%"])
            ->orderBy('name')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $symbols->map(fn (ProjectIndexSymbol $s) => [
                'kind' => $s->kind,
                'name' => $s->name,
                'path' => $s->file?->path,
                'line' => $s->line,
                'detail' => $s->detail,
            ]),
        ]);
    }

    /**
     * @param  \Closure(): mixed  $action
     */
    private function run(\Closure $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (RunnerUnavailableException|OperationsHaltedException $e) {
            return back()->withErrors(['runner' => $e->getMessage()]);
        }

        return back()->with('status', $success);
    }
}
