<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Location extends Model
{
    protected $fillable = [
        'region_name',
        'latitude',
        'longitude',
        'country',
        'country_code',
        'continent',
        'timezone',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'media_locations', 'location_id', 'media_id')
            ->withTimestamps();
    }
}
