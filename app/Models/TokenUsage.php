<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenUsage extends Model
{
    public $timestamps = false;

    protected $table = 'token_usage';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'input_cost' => 'decimal:6',
            'cached_input_cost' => 'decimal:6',
            'output_cost' => 'decimal:6',
            'total_cost' => 'decimal:6',
            'pricing_snapshot' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }
}
