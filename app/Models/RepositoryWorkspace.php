<?php

namespace App\Models;

use App\Enums\WorkspaceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A task's isolated Git worktree on a developer machine (Plan §13).
 */
class RepositoryWorkspace extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => WorkspaceStatus::class,
            'removed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<LocalRunner, $this>
     */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(LocalRunner::class, 'local_runner_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'task_key' => $this->task_key,
            'branch' => $this->branch,
            'path' => $this->path,
            'base_branch' => $this->base_branch,
            'base_sha' => $this->base_sha,
            'last_checkpoint_sha' => $this->last_checkpoint_sha,
            'status' => $this->status->value,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
