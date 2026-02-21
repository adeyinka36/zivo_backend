<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Interest extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'order',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_interests')
            ->withPivot('last_changed_at')
            ->withTimestamps();
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'media_interests')
            ->withTimestamps();
    }
}
