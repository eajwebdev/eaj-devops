<?php

namespace App\Models;

use App\Enums\ModelTier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiModel extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tier' => ModelTier::class,
            'input_cost' => 'decimal:6',
            'cached_input_cost' => 'decimal:6',
            'output_cost' => 'decimal:6',
            'enabled' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
