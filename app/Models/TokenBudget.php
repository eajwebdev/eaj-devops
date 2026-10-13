<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenBudget extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'max_cost' => 'decimal:6',
            'hard_stop' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
