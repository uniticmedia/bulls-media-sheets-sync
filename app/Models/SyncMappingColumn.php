<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncMappingColumn extends Model
{
    protected $fillable = ['mapping_id', 'source_column', 'target_column', 'data_type'];

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(SyncMapping::class, 'mapping_id');
    }
}