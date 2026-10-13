<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GithubReview extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }
}
