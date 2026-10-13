<?php

namespace App\Models;

use App\Enums\BlockedReason;
use App\Enums\WorkflowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A durable, persisted state machine (Plan §48.2). Mutate it only through
 * App\Workflow\WorkflowEngine, which applies row locking and versioning.
 */
class Workflow extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => WorkflowStatus::class,
            'blocked_reason' => BlockedReason::class,
            'limits' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<WorkflowStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('position');
    }

    /**
     * @return HasMany<WorkflowCheckpoint, $this>
     */
    public function checkpoints(): HasMany
    {
        return $this->hasMany(WorkflowCheckpoint::class)->orderBy('id');
    }

    public function limit(string $name): int
    {
        return (int) ($this->limits[$name] ?? config("eaj.limits.{$name}"));
    }
}
