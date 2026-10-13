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
        return ['recorded_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }
}
