<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * organization_id is set through the relationship, never from request input.
     */
    protected $fillable = ['key', 'name', 'slug', 'description'];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasOne<ProjectConnection, $this>
     */
    public function connection(): HasOne
    {
        return $this->hasOne(ProjectConnection::class);
    }

    /**
     * @return HasMany<OutboxEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(OutboxEvent::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<ProjectSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(ProjectSnapshot::class);
    }

    /**
     * @return HasMany<RepositoryWorkspace, $this>
     */
    public function workspaces(): HasMany
    {
        return $this->hasMany(RepositoryWorkspace::class);
    }

    /**
     * @return HasMany<RunnerCommand, $this>
     */
    public function runnerCommands(): HasMany
    {
        return $this->hasMany(RunnerCommand::class);
    }

    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    /** @return HasMany<ProjectTool, $this> */
    public function tools(): HasMany
    {
        return $this->hasMany(ProjectTool::class);
    }

    public function githubRepository(): HasOne
    {
        return $this->hasOne(GithubRepository::class);
    }

    public function eventStream(): string
    {
        return 'project.'.$this->getKey();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
