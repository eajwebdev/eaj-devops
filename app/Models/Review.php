<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One review gate (QA, senior, security) for one commit. The verdict is the
 * Studio's, computed from the reviewer's findings — never the reviewer's own
 * "passed" (Plan §98, §99). Written only by App\Delivery\Steps\ReviewStep.
 */
class Review extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
            'issues' => 'array',
            'acceptance' => 'array',
            'blocking' => 'array',
            'warnings' => 'array',
        ];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return array<string, mixed> */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'gate' => $this->gate,
            'cycle' => $this->cycle,
            'head_sha' => $this->head_sha,
            'passed' => $this->passed,
            'score' => $this->score,
            'minimum_score' => $this->minimum_score,
            'summary' => $this->summary,
            'confidence' => $this->confidence,
            'issues' => $this->issues ?? [],
            'acceptance' => $this->acceptance ?? [],
            'blocking' => $this->blocking ?? [],
            'warnings' => $this->warnings ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
