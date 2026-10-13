<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetAlert extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'used' => 'decimal:6',
            'limit_value' => 'decimal:6',
            'hard_stop' => 'boolean',
            'emitted_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(TokenBudget::class, 'token_budget_id');
    }
}
