<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Github\GithubException;
use App\Github\GithubReleaseService;
use App\Github\GithubRepositoryService;
use App\Models\GithubPullRequest;
use App\Models\Project;
use App\Models\Task;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskGithubController extends Controller
{
    public function store(Request $request, Project $project, Task $task, GithubReleaseService $release): RedirectResponse
    {
        $this->authorize('manage', $task);
        try {
            $pull = $release->publishOrCreate($task->setRelation('project', $project), $request->user());
        } catch (DomainException|GithubException $e) {
            return back()->withErrors(['github' => $e->getMessage()]);
        }

        return back()->with('status', $pull ? "Pull request #{$pull->github_number} is ready." : 'Publishing the reviewed task branch. Create the pull request after the runner finishes.');
    }

    public function sync(Request $request, Project $project, Task $task, GithubPullRequest $githubPullRequest, GithubRepositoryService $repositories): RedirectResponse
    {
        $this->authorize('view', $task);
        $repositories->refreshPullRequest($githubPullRequest);

        return back()->with('status', "Pull request #{$githubPullRequest->github_number} synchronized.");
    }

    public function merge(Request $request, Project $project, Task $task, GithubPullRequest $githubPullRequest, GithubReleaseService $release): RedirectResponse
    {
        abort_unless($request->user()->hasPermissionIn($project->organization, Permission::TasksApprove), 403);
        $data = $request->validate(['method' => ['required', Rule::in(['merge', 'squash', 'rebase'])]]);
        try {
            $release->merge($githubPullRequest, $task->setRelation('project', $project), $request->user(), $data['method']);
        } catch (DomainException|GithubException $e) {
            return back()->withErrors(['github' => $e->getMessage()]);
        }

        return back()->with('status', "Pull request #{$githubPullRequest->github_number} merged with a pre-merge backup tag.");
    }
}
