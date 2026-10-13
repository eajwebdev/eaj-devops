<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRole extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['responsibilities' => 'array', 'may_implement' => 'boolean'];
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
}
