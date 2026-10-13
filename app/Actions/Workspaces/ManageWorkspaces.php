<?php

namespace App\Actions\Workspaces;

use App\Enums\RunnerCommandType;
use App\Enums\WorkspaceStatus;
use App\Models\GithubRepository;
use App\Models\Project;
use App\Models\RepositoryWorkspace;
use App\Models\Task;
use App\Models\User;
use App\Runner\Exceptions\RunnerUnavailableException;
use App\Runner\RunnerGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Workspace lifecycle (Plan §13, §48.4): create → checkpoint → (reset) →
 * remove. The task engine (Phase 3) and agent engine (Phase 4) drive this;
 * the runner performs every Git operation on the PC.
 */
class ManageWorkspaces
{
    public function __construct(private readonly RunnerGateway $gateway) {}

    public function create(Project $project, string $taskKey, string $title, ?User $user, ?string $baseBranch = null, ?Task $task = null): RepositoryWorkspace
    {
        if (! preg_match('/^[A-Z][A-Z0-9]{1,9}-\d{1,7}$/', $taskKey)) {
            throw new InvalidArgumentException("Invalid task key [{$taskKey}].");
        }

        $connection = $project->connection;
        $runner = $connection?->runner;

        if (! $runner || $runner->revoked_at || ! $connection->local_path) {
            throw new RunnerUnavailableException('This project has no active EAJ Local Runner.');
        }

        if (! $connection->git_enabled) {
            throw new RunnerUnavailableException('Initialize Git for this project before creating task workspaces (Plan §14).');
        }

        $slug = Str::limit(Str::slug($title), 40, '') ?: 'task';
        $slug = trim($slug, '-');

        return DB::transaction(function () use ($project, $taskKey, $slug, $user, $baseBranch, $connection, $runner, $task) {
            $existing = RepositoryWorkspace::where('project_id', $project->id)->where('task_key', $taskKey)->lockForUpdate()->first();

            if ($existing && $existing->status !== WorkspaceStatus::Removed && $existing->status !== WorkspaceStatus::Failed) {
                return $existing;
            }

            $workspace = $existing ?? new RepositoryWorkspace;
            $workspace->forceFill([
                'project_id' => $project->id,
                'task_id' => $task?->id,
                'local_runner_id' => $runner->id,
                'task_key' => $taskKey,
                'branch' => "ai/{$taskKey}-{$slug}",
                'status' => WorkspaceStatus::Creating,
                'error' => null,
                'removed_at' => null,
                'created_by' => $user?->id,
            ])->save();

            $this->gateway->dispatch($runner, RunnerCommandType::WorkspaceCreate, [
                'workspace_id' => $workspace->id,
                'project_path' => $connection->local_path,
                'task_key' => $taskKey,
                'slug' => $slug,
                'base_branch' => $baseBranch,
            ], $project, $user);

            return $workspace;
        });
    }

    public function checkpoint(RepositoryWorkspace $workspace, int $step, ?User $user = null): void
    {
        $this->requireReady($workspace);

        $this->gateway->dispatch($workspace->runner, RunnerCommandType::WorkspaceCheckpoint, [
            'workspace_id' => $workspace->id,
            'workspace_path' => $workspace->path,
            'task_key' => $workspace->task_key,
            'step' => $step,
        ], $workspace->project, $user, "checkpoint:{$workspace->id}:{$step}");
    }

    public function publish(RepositoryWorkspace $workspace, GithubRepository $repository, ?User $user = null): void
    {
        $this->requireReady($workspace);

        if (! $workspace->last_checkpoint_sha) {
            throw new RunnerUnavailableException("Workspace {$workspace->task_key} has no reviewed checkpoint to publish.");
        }

        $this->gateway->dispatch($workspace->runner, RunnerCommandType::WorkspacePush, [
            'workspace_id' => $workspace->id,
            'workspace_path' => $workspace->path,
            'branch' => $workspace->branch,
            'repository_full_name' => $repository->full_name,
        ], $workspace->project, $user, "publish:{$workspace->id}:{$workspace->last_checkpoint_sha}");
    }

    public function resetToCheckpoint(RepositoryWorkspace $workspace, string $sha, ?User $user = null): void
    {
        $this->requireReady($workspace);

        $this->gateway->dispatch($workspace->runner, RunnerCommandType::WorkspaceReset, [
            'workspace_id' => $workspace->id,
            'workspace_path' => $workspace->path,
            'sha' => $sha,
        ], $workspace->project, $user);
    }

    /**
     * Removing a workspace with uncommitted changes needs explicit approval ($force).
     */
    public function remove(RepositoryWorkspace $workspace, ?User $user, bool $force = false): void
    {
        $this->requireReady($workspace);

        DB::transaction(function () use ($workspace, $user, $force) {
            $workspace->forceFill(['status' => WorkspaceStatus::Removing])->save();

            $this->gateway->dispatch($workspace->runner, RunnerCommandType::WorkspaceRemove, [
                'workspace_id' => $workspace->id,
                'workspace_path' => $workspace->path,
                'project_path' => $workspace->project->connection->local_path,
                'force' => $force,
            ], $workspace->project, $user);
        });
    }

    private function requireReady(RepositoryWorkspace $workspace): void
    {
        if ($workspace->status !== WorkspaceStatus::Ready || ! $workspace->path || ! $workspace->runner || $workspace->runner->revoked_at) {
            throw new RunnerUnavailableException("Workspace {$workspace->task_key} is not ready.");
        }
    }
}
