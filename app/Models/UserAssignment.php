<?php

namespace App\Models;

use App\Jobs\SendCurrentMediaNotificationJob;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAssignment extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'user_id',
        'media_id',
        'current',
    ];

    protected $casts = [
        'current' => 'boolean',
    ];

    /**
     * Boot the model and register event listeners
     */
    protected static function boot(): void
    {
        parent::boot();

        static::updated(function (UserAssignment $assignment) {
            if ($assignment->isDirty('current') && $assignment->current === true) {
                $originalCurrent = $assignment->getOriginal('current');
                
                if ($originalCurrent === false || $originalCurrent === null) {
                    $user = $assignment->user;
                    
                    if ($user && $user->push_notifications) {
                        SendCurrentMediaNotificationJob::dispatch(
                            $assignment->user_id,
                            $assignment->media_id,
                            'assigned'
                        );
                    }
                }
            }
        });

        static::deleted(function (UserAssignment $assignment) {
            if ($assignment->current === true) {
                $user = $assignment->user ?? User::find($assignment->user_id);
                
                if ($user && $user->push_notifications) {
                    SendCurrentMediaNotificationJob::dispatch(
                        $assignment->user_id,
                        $assignment->media_id,
                        'removed'
                    );
                }
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}

