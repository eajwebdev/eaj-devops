<?php

namespace App\Models;

use App\Enums\ToolKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of a project's approved command set (Plan §84). Detected by the
 * runner from the project's own files; the owner decides whether agents may
 * run it. Synced only through App\Tools\ToolCatalog.
 */
class ProjectTool extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'kind' => ToolKind::class,
            'supports_fix' => 'boolean',
            'scoped' => 'boolean',
            'filterable' => 'boolean',
            'enabled' => 'boolean',
            'available' => 'boolean',
            'detected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Agents may run it: still detected, switched on, and not refused by the runner's safety rules. */
    public function runnable(): bool
    {
        return $this->available && $this->enabled && ! $this->blocked;
    }

    /** @return array<string, mixed> */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'ecosystem' => $this->ecosystem,
            'label' => $this->label,
            'command' => $this->command,
            'supports_fix' => $this->supports_fix,
            'scoped' => $this->scoped,
            'blocked' => $this->blocked,
            'enabled' => $this->enabled,
            'available' => $this->available,
        ];
    }
}
