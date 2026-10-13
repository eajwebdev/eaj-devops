<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GithubBranch extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['protected' => 'boolean', 'synced_at' => 'datetime'];
    }
}
