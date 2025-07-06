<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MediaUserWatched extends Model
{
    protected $table = 'media_users_watched';

    protected $fillable = [
        'user_id',
        'media_id',
        'created_at',
    ];

    public $timestamps = false;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->created_at = now();
            $model->id = (string) Str::uuid();
        });
    }

    /**
     * Get the user that watched the media.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the media that was watched.
     */
    public function media()
    {
        return $this->belongsTo(Media::class);
    }
}
