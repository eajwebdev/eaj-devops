<?php

namespace App\Models;

use App\Enums\StepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStep extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => StepStatus::class,
            'input' => 'array',
            'output' => 'array',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<WorkflowCheckpoint, $this>
     */
    public function resumeCheckpoint(): BelongsTo
    {
        return $this->belongsTo(WorkflowCheckpoint::class, 'resume_checkpoint_id');
    }

    /**
     * §48.3 — `workflow:step:attempt`; unique per execution attempt.
     */
    public function idempotencyKey(): string
    {
        return "workflow:{$this->workflow_id}:step:{$this->position}:attempt:{$this->attempt}";
    }
}
