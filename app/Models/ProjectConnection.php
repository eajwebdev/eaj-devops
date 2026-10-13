<?php

namespace App\Models;

use App\Enums\ProjectConnectionStatus;
use App\Enums\ProjectConnectionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectConnection extends Model
{
    protected $fillable = ['type', 'local_path', 'repository_url', 'default_branch'];

    protected function casts(): array
    {
        return [
            'type' => ProjectConnectionType::class,
            'status' => ProjectConnectionStatus::class,
            'git_enabled' => 'boolean',
            'detected_stack' => 'array',
            'git_state' => 'array',
            'warnings' => 'array',
            'index_stats' => 'array',
            'last_scanned_at' => 'datetime',
            'indexed_at' => 'datetime',
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
     * @return BelongsTo<LocalRunner, $this>
     */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(LocalRunner::class, 'local_runner_id');
    }
}
