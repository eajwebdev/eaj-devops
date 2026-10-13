<?php

namespace App\Models;

use App\Enums\RunnerCommandStatus;
use App\Enums\RunnerCommandType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signed, idempotent instruction for a Local Runner. Create and advance
 * it only through App\Runner\RunnerGateway.
 */
class RunnerCommand extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => RunnerCommandType::class,
            'status' => RunnerCommandStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'claimed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LocalRunner, $this>
     */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(LocalRunner::class, 'local_runner_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'status' => $this->status->value,
            'progress' => $this->progress,
            'error' => $this->error_message,
            'error_code' => $this->error_code,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
