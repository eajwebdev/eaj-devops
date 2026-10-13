<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkillVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'supported_frameworks' => 'array',
            'supported_versions' => 'array',
            'responsibilities' => 'array',
            'rules' => 'array',
            'forbidden_actions' => 'array',
            'tools' => 'array',
            'validation_expectations' => 'array',
            'references' => 'array',
            'output_schema' => 'array',
            'active' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
