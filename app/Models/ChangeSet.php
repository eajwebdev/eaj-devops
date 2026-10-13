<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * File changes one agent step made, exactly as the runner applied them.
 * Written only by App\Delivery\Steps\ImplementStep.
 */
class ChangeSet extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'files' => 'array',
            'out_of_scope' => 'array',
            'warnings' => 'array',
        ];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    /** @return array<string, mixed> */
    public function toClientArray(bool $withDiff = false): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'mode' => $this->mode,
            'cycle' => $this->cycle,
            'status' => $this->status,
            'summary' => $this->summary,
            'confidence' => $this->confidence,
            'files' => $this->files ?? [],
            'out_of_scope' => $this->out_of_scope ?? [],
            'warnings' => $this->warnings ?? [],
            'additions' => $this->additions,
            'deletions' => $this->deletions,
            'created_at' => $this->created_at?->toIso8601String(),
            ...($withDiff ? ['diff' => $this->diff] : []),
        ];
    }
}
