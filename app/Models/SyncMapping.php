<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SyncMapping extends Model
{
    protected $fillable = ['source_id', 'tab_name', 'target_table', 'header_row', 'is_active'];

    protected $casts = [
        'header_row' => 'integer',
        'is_active' => 'boolean',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(SyncSource::class, 'source_id');
    }

    public function columns(): HasMany
    {
        return $this->hasMany(SyncMappingColumn::class, 'mapping_id')->orderBy('id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SyncRun::class, 'sync_mapping_id');
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(SyncRun::class, 'sync_mapping_id')->latestOfMany();
    }
}