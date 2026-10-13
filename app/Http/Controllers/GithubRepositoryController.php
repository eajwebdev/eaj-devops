<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Github\GithubApi;
use App\Github\GithubException;
use App\Github\GithubRepositoryService;
use App\Models\GithubRepository;
use App\Models\Project;
use App\Reliability\AuditLogger;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GithubRepositoryController extends Controller
{
    public function index(Request $request, CurrentOrganization $current, GithubApi $api): Response
    {
        $organization = $current->get();
        abort_unless($request->user()->hasPermissionIn($organization, Permission::ProjectsView), 403);
        $connection = $organization->githubConnection()->whereNull('revoked_at')->first();
        $available = [];
        $error = null;
        if ($connection) {
            try {
                $available = collect($api->repositories($connection))->map(fn (array $repo) => [
                    'id' => $repo['id'], 'full_name' => $repo['full_name'], 'name' => $repo['name'],
                    'private' => (bool) ($repo['private'] ?? false), 'archived' => (bool) ($repo['archived'] ?? false),
                    'default_branch' => $repo['default_branch'] ?? 'main', 'html_url' => $repo['html_url'],
                ])->values();
            } catch (GithubException $e) {
                $error = $e->getMessage();
            }
        }

        $linked = $organization->projects()->whereHas('githubRepository')->with('githubRepository.branches', 'githubRepository.pullRequests.reviews')->orderBy('name')->get()
            ->map(fn (Project $project) => $this->present($project->githubRepository, $project));

        return Inertia::render('repositories/index', [
            'connection' => $connection ? ['login' => $connection->login, 'avatar_url' => $connection->avatar_url, 'scopes' => $connection->scopes, 'last_used_at' => $connection->last_used_at?->toIso8601String()] : null,
            'available' => $available,
            'linked' => $linked,
            'projects' => $organization->projects()->doesntHave('githubRepository')->orderBy('name')->get(['id', 'key', 'name', 'slug']),
            'githubError' => $error,
            'can' => [
                'connect' => $request->user()->hasPermissionIn($organization, Permission::SettingsManage),
                'manage' => $request->user()->hasPermissionIn($organization, Permission::ProjectsUpdate),
            ],
        ]);
    }

    public function attach(Request $request, Project $project, CurrentOrganization $current, GithubApi $api, GithubRepositoryService $repositories, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate(['full_name' => ['required', 'string', 'max:200', 'regex:#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#']]);
        $connection = $current->get()->githubConnection()->whereNull('revoked_at')->first();
        if (! $connection) {
            return back()->withErrors(['github' => 'Connect GitHub first.']);
        }

        try {
            $repo = $repositories->syncRepository($connection, $api->repository($connection, $data['full_name']), $project);
            $repositories->sync($repo);
            $project->connection?->forceFill(['repository_url' => $repo->clone_url, 'default_branch' => $repo->default_branch])->save();
            $audit->log('github.repository.attached', $request->user(), $project->organization, $project, metadata: ['repository' => $repo->full_name]);
        } catch (GithubException $e) {
            return back()->withErrors(['github' => $e->getMessage()]);
        }

        return back()->with('status', "{$repo->full_name} attached to {$project->name}.");
    }

    public function sync(Request $request, GithubRepository $githubRepository, GithubRepositoryService $repositories): RedirectResponse
    {
        $this->authorize('update', $githubRepository->project);
        try {
            $repositories->sync($githubRepository);
        } catch (GithubException $e) {
            return back()->withErrors(['github' => $e->getMessage()]);
        }

        return back()->with('status', "{$githubRepository->full_name} synchronized.");
    }

    private function present(GithubRepository $repo, Project $project): array
    {
        return [
            'id' => $repo->id, 'project' => $project->only('id', 'key', 'name', 'slug'), 'full_name' => $repo->full_name,
            'html_url' => $repo->html_url, 'default_branch' => $repo->default_branch, 'private' => $repo->private,
            'synced_at' => $repo->synced_at?->toIso8601String(),
            'branches' => $repo->branches->map->only(['name', 'head_sha', 'protected'])->values(),
            'pull_requests' => $repo->pullRequests->sortByDesc('github_number')->take(10)->map(fn ($pull) => [
                'number' => $pull->github_number, 'title' => $pull->title, 'state' => $pull->state, 'html_url' => $pull->html_url,
                'head_sha' => $pull->head_sha, 'reviews' => $pull->reviews->map->only(['reviewer_login', 'state', 'commit_id'])->values(),
            ])->values(),
        ];
    }
}
