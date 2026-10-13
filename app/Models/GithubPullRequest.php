<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GithubPullRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['draft' => 'boolean', 'last_synced_at' => 'datetime', 'merged_at' => 'datetime'];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(GithubRepository::class, 'github_repository_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(GithubReview::class);
    }
}
