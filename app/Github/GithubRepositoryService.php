<?php

namespace App\Github;

use App\Models\GithubConnection;
use App\Models\GithubPullRequest;
use App\Models\GithubRepository;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

class GithubRepositoryService
{
    public function __construct(private readonly GithubApi $api) {}

    public function syncRepository(GithubConnection $connection, array $data, ?Project $project = null): GithubRepository
    {
        $fullName = (string) ($data['full_name'] ?? '');
        if (! preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $fullName)) {
            throw new GithubException('GitHub returned an invalid repository name.');
        }

        [$owner, $name] = explode('/', $fullName, 2);
        $existing = GithubRepository::where('organization_id', $connection->organization_id)->where('github_id', (int) $data['id'])->first();
        if ($project && $existing?->project_id && $existing->project_id !== $project->id) {
            throw new GithubException("{$fullName} is already attached to another project.");
        }
        $repository = GithubRepository::updateOrCreate([
            'organization_id' => $connection->organization_id,
            'github_id' => (int) $data['id'],
        ], [
            'github_connection_id' => $connection->id,
            'project_id' => $project?->id,
            'owner' => $owner,
            'name' => $name,
            'full_name' => $fullName,
            'default_branch' => (string) ($data['default_branch'] ?? 'main'),
            'clone_url' => (string) ($data['clone_url'] ?? "https://github.com/{$fullName}.git"),
            'html_url' => (string) ($data['html_url'] ?? "https://github.com/{$fullName}"),
            'private' => (bool) ($data['private'] ?? false),
            'archived' => (bool) ($data['archived'] ?? false),
            'permissions' => (array) ($data['permissions'] ?? []),
            'synced_at' => now(),
        ]);

        return $repository;
    }

    public function sync(GithubRepository $repository): GithubRepository
    {
        return DB::transaction(function () use ($repository) {
            $connection = $repository->connection;
            $repository = $this->syncRepository($connection, $this->api->repository($connection, $repository->full_name), $repository->project);
            $seen = [];

            foreach ($this->api->branches($connection, $repository->full_name) as $branch) {
                $name = (string) ($branch['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $seen[] = $name;
                $repository->branches()->updateOrCreate(['name' => $name], [
                    'head_sha' => (string) data_get($branch, 'commit.sha'),
                    'protected' => (bool) ($branch['protected'] ?? false),
                    'synced_at' => now(),
                ]);
            }
            $repository->branches()->whereNotIn('name', $seen)->delete();

            foreach ($this->api->pullRequests($connection, $repository->full_name) as $pull) {
                $this->syncPullRequest($repository, $pull);
            }

            return $repository->fresh(['branches', 'pullRequests.reviews']);
        });
    }

    public function syncPullRequest(GithubRepository $repository, array $data, ?Task $task = null): GithubPullRequest
    {
        $number = (int) ($data['number'] ?? 0);
        if ($number < 1) {
            throw new GithubException('GitHub returned an invalid pull request.');
        }

        $pull = GithubPullRequest::updateOrCreate([
            'github_repository_id' => $repository->id,
            'github_number' => $number,
        ], [
            'organization_id' => $repository->organization_id,
            'project_id' => $repository->project_id,
            'task_id' => $task?->id ?? GithubPullRequest::where('github_repository_id', $repository->id)->where('github_number', $number)->value('task_id'),
            'node_id' => $data['node_id'] ?? null,
            'title' => (string) ($data['title'] ?? "Pull request #{$number}"),
            'body' => $data['body'] ?? null,
            'state' => (string) ($data['state'] ?? 'open'),
            'draft' => (bool) ($data['draft'] ?? false),
            'head_branch' => (string) data_get($data, 'head.ref'),
            'base_branch' => (string) data_get($data, 'base.ref'),
            'head_sha' => (string) data_get($data, 'head.sha'),
            'base_sha' => data_get($data, 'base.sha'),
            'merge_commit_sha' => $data['merge_commit_sha'] ?? null,
            'mergeable_state' => $data['mergeable_state'] ?? null,
            'html_url' => (string) ($data['html_url'] ?? $repository->html_url.'/pull/'.$number),
            'last_synced_at' => now(),
            'merged_at' => isset($data['merged_at']) ? $data['merged_at'] : null,
        ]);

        if ($task || $pull->task_id) {
            $this->syncReviews($pull, $this->api->reviews($repository->connection, $repository->full_name, $number));
        }

        return $pull->fresh('reviews');
    }

    public function refreshPullRequest(GithubPullRequest $pull): GithubPullRequest
    {
        $pull->loadMissing('repository.connection', 'task');

        return $this->syncPullRequest(
            $pull->repository,
            $this->api->pullRequest($pull->repository->connection, $pull->repository->full_name, $pull->github_number),
            $pull->task,
        );
    }

    public function syncReviews(GithubPullRequest $pull, array $reviews): void
    {
        foreach ($reviews as $review) {
            if (! isset($review['id'])) {
                continue;
            }
            $pull->reviews()->updateOrCreate(['github_id' => (int) $review['id']], [
                'reviewer_login' => (string) data_get($review, 'user.login', 'unknown'),
                'state' => strtoupper((string) ($review['state'] ?? 'COMMENTED')),
                'body' => $review['body'] ?? null,
                'commit_id' => $review['commit_id'] ?? null,
                'html_url' => $review['html_url'] ?? null,
                'submitted_at' => $review['submitted_at'] ?? null,
            ]);
        }
    }
}
