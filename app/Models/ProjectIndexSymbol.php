<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectIndexSymbol extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * @return BelongsTo<ProjectIndexFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(ProjectIndexFile::class, 'file_id');
    }
}
