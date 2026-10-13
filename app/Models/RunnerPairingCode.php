<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RunnerPairingCode extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
