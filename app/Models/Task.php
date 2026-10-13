<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * A unit of work on a project (Plan Phase 3). Change status only through
 * App\Tasks\TaskService, which enforces the state machine (§31).
 */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'acceptance_criteria' => 'array',
            'type' => TaskType::class,
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'blocked_from' => TaskStatus::class,
            'impact' => 'array',
            'impact_analyzed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Tasks this one waits for.
     *
     * @return BelongsToMany<Task, $this>
     */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'task_id', 'depends_on_task_id');
    }

    /**
     * Tasks waiting for this one.
     *
     * @return BelongsToMany<Task, $this>
     */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'depends_on_task_id', 'task_id');
    }

    /**
     * @return HasMany<TaskComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('id');
    }

    /**
     * @return HasMany<TaskTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(TaskTransition::class)->orderBy('id');
    }

    /**
     * @return HasOne<RepositoryWorkspace, $this>
     */
    public function workspace(): HasOne
    {
        return $this->hasOne(RepositoryWorkspace::class)->latestOfMany();
    }

    /** @return HasMany<AgentRun, $this> */
    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    /** @return HasOne<AgentRun, $this> */
    public function latestAgentRun(): HasOne
    {
        return $this->hasOne(AgentRun::class)->latestOfMany();
    }

    /** "BOOK-12" — also the workspace task key and branch name part. */
    public function key(): string
    {
        return ($this->relationLoaded('project') ? $this->project->key : Project::whereKey($this->project_id)->value('key')).'-'.$this->number;
    }

    /**
     * Dependencies not finished yet: the task cannot start until these are DONE (§54).
     *
     * @return Collection<int, Task>
     */
    public function openDependencies()
    {
        return $this->dependencies->reject(fn (Task $t) => $t->status === TaskStatus::Done)->values();
    }
}
