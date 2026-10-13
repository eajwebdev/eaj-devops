<?php

namespace App\Github;

use App\Actions\Workspaces\ManageWorkspaces;
use App\Delivery\DeliveryWorkflow;
use App\Delivery\Steps\ReviewDecisionStep;
use App\Enums\StepStatus;
use App\Enums\TaskStatus;
use App\Models\GithubPullRequest;
use App\Models\Task;
use App\Models\User;
use App\Reliability\AuditLogger;
use App\Reliability\EventRecorder;
use DomainException;

class GithubReleaseService
{
    public function __construct(
        private readonly GithubApi $api,
        private readonly GithubRepositoryService $repositories,
        private readonly ManageWorkspaces $workspaces,
        private readonly DeliveryWorkflow $delivery,
        private readonly EventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    public function publishOrCreate(Task $task, User $user): ?GithubPullRequest
    {
        $task->loadMissing('project.organization', 'project.githubRepository.connection', 'workspace');
        if (! in_array($task->status, [TaskStatus::ReleaseReady, TaskStatus::Done], true)) {
            throw new DomainException("{$task->key()} must be Release Ready before publishing.");
        }
        $repository = $task->project->githubRepository;
        $workspace = $task->workspace;
        if (! $repository) {
            throw new DomainException('Attach a GitHub repository to this project first.');
        }
        if (! $workspace) {
            throw new DomainException('This task has no workspace.');
        }

        $approved = $this->approvedHead($task);
        if (! $approved || ! hash_equals($approved, (string) $workspace->last_checkpoint_sha)) {
            throw new DomainException('The latest checkpoint is not the commit approved by every delivery review.');
        }

        if (! $workspace->published_sha || ! hash_equals($approved, $workspace->published_sha)) {
            $this->workspaces->publish($workspace, $repository, $user);

            return null;
        }

        if ($existing = $task->pullRequests()->where('state', 'open')->latest()->first()) {
            return $this->repositories->refreshPullRequest($existing);
        }

        $data = $this->api->createPullRequest($repository->connection, $repository->full_name, [
            'title' => "{$task->key()}: {$task->title}",
            'head' => $workspace->branch,
            'base' => $repository->default_branch,
            'body' => $this->body($task),
        ]);
        $pull = $this->repositories->syncPullRequest($repository, $data, $task);
        $this->events->record('github.pull_request.created', ['task_id' => $task->id, 'number' => $pull->github_number, 'url' => $pull->html_url, 'head_sha' => $pull->head_sha], $task->project, actor: $user);
        $this->audit->log('github.pull_request.created', $user, $task->project->organization, $task->project, $task, ['number' => $pull->github_number, 'head_sha' => $pull->head_sha]);

        return $pull;
    }

    public function merge(GithubPullRequest $pull, Task $task, User $user, string $method): GithubPullRequest
    {
        if ($task->status !== TaskStatus::Done) {
            throw new DomainException('Approve the Release Ready task before merging its pull request.');
        }
        $pull = $this->repositories->refreshPullRequest($pull);
        $approved = $this->approvedHead($task);
        $workspace = $task->workspace;

        if (! $approved || ! $workspace || ! hash_equals($approved, $pull->head_sha) || ! hash_equals($approved, (string) $workspace->published_sha)) {
            throw new DomainException('The pull request head is not the exact commit approved by the delivery reviews.');
        }
        if ($pull->state !== 'open' || $pull->merged_at) {
            throw new DomainException('This pull request is no longer open.');
        }
        if (! $workspace->base_sha || ! $pull->base_sha || ! hash_equals($workspace->base_sha, $pull->base_sha)) {
            throw new DomainException('The base branch changed after this workspace was created. Rebase and run the delivery reviews again.');
        }

        $repository = $pull->repository()->with('connection')->firstOrFail();
        $tag = 'tags/eaj-backup/'.$task->key().'/'.now()->format('YmdHis');
        $this->api->createReference($repository->connection, $repository->full_name, $tag, $pull->base_sha);
        $result = $this->api->merge($repository->connection, $repository->full_name, $pull->github_number, $approved, $method);
        if (! ($result['merged'] ?? false)) {
            throw new GithubException((string) ($result['message'] ?? 'GitHub did not merge the pull request.'));
        }

        $pull->forceFill(['state' => 'closed', 'merged_at' => now(), 'merge_commit_sha' => $result['sha'] ?? $pull->merge_commit_sha])->save();
        $this->events->record('github.pull_request.merged', ['task_id' => $task->id, 'number' => $pull->github_number, 'head_sha' => $approved, 'backup_ref' => $tag], $task->project, actor: $user);
        $this->audit->log('github.pull_request.merged', $user, $task->project->organization, $task->project, $task, ['number' => $pull->github_number, 'head_sha' => $approved, 'backup_ref' => $tag, 'method' => $method]);

        return $pull->fresh('reviews');
    }

    public function approvedHead(Task $task): ?string
    {
        $workflow = $this->delivery->latest($task);
        if (! $workflow) {
            return null;
        }

        return $workflow->steps()->where('name', ReviewDecisionStep::NAME)->where('status', StepStatus::Completed)
            ->latest('position')->get()->first(fn ($step) => data_get($step->output, 'decision') === 'release_ready')?->output['head_sha'] ?? null;
    }

    private function body(Task $task): string
    {
        $criteria = collect($task->acceptance_criteria ?? [])->filter()->map(fn ($item) => "- [ ] {$item}")->join("\n");

        return "## {$task->key()}\n\n".($task->description ?: $task->title).($criteria ? "\n\n### Acceptance criteria\n\n{$criteria}" : '')."\n\nCreated by EAJ AI Dev Studio after automated checks and review.";
    }
}
