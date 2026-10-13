<?php

namespace App\Models;

use App\Enums\AgentRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AgentRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => AgentRunStatus::class,
            'input' => 'array',
            'output' => 'array',
            'total_cost' => 'decimal:6',
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(AgentRole::class, 'agent_role_id');
    }

    public function modelRoute(): BelongsTo
    {
        return $this->belongsTo(ModelRoute::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(PromptVersion::class);
    }

    public function providerCalls(): HasMany
    {
        return $this->hasMany(AiProviderCall::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AgentEvent::class)->orderBy('sequence');
    }

    public function latestEvent(): HasOne
    {
        return $this->hasOne(AgentEvent::class)->latestOfMany('sequence');
    }

    public function usage(): HasMany
    {
        return $this->hasMany(TokenUsage::class);
    }

    public function skillVersions(): BelongsToMany
    {
        return $this->belongsToMany(SkillVersion::class, 'agent_run_skills')->withPivot('created_at');
    }
}
