<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectIndexFile extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * @return HasMany<ProjectIndexSymbol, $this>
     */
    public function symbols(): HasMany
    {
        return $this->hasMany(ProjectIndexSymbol::class, 'file_id');
    }
}
