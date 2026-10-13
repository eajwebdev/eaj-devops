<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A persisted domain event (Plan §48.6). Rows are append-only; create them
 * only through App\Reliability\EventRecorder so the sequence stays gapless.
 */
class OutboxEvent extends Model
{
    /**
     * Per-command chatter that drives live progress but is not shown in activity feeds
     * (Plan §61: meaningful events only). Mirrors QUIET_TYPES in resources/js/lib/events.ts.
     */
    public const QUIET_TYPES = ['runner.command.queued', 'runner.command.started', 'runner.command.progress', 'runner.command.succeeded', 'task.reordered', 'task.impact.analyzed', 'agent.run.tokens_recorded'];

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'published_at' => 'datetime',
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
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * The shape sent to clients, both over the socket and from the catch-up API.
     *
     * @return array<string, mixed>
     */
    public function toClientPayload(): array
    {
        return [
            'id' => $this->id,
            'stream' => $this->stream,
            'sequence' => $this->sequence,
            'type' => $this->type,
            'project_id' => $this->project_id,
            'payload' => $this->payload,
            'actor' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name] : null,
            'project' => $this->relationLoaded('project') && $this->project
                ? $this->project->only('id', 'key', 'name', 'slug')
                : null,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
