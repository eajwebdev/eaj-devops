<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GithubRepository extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['private' => 'boolean', 'archived' => 'boolean', 'permissions' => 'array', 'synced_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GithubConnection::class, 'github_connection_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(GithubBranch::class);
    }

    public function pullRequests(): HasMany
    {
        return $this->hasMany(GithubPullRequest::class);
    }
}
