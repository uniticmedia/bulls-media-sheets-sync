<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncSource extends Model
{
    protected $fillable = ['name', 'spreadsheet_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function mappings(): HasMany
    {
        return $this->hasMany(SyncMapping::class, 'source_id');
    }
}