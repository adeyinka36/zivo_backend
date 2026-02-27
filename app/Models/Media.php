<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Models\MediaUserWatched;
use App\Jobs\AssignMediaToUsersJob;
use App\Jobs\GenerateThumbnailJob;
use App\Jobs\SendEmailNotificationJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class Media extends Model
{
    use HasFactory, HasUuids;

//    public mixed $locations;
    protected $fillable = [
        'creator_id',
        'name',
        'file_name',
        'mime_type',
        'size',
        'path',
        'temp_file_path',
        'disk',
        'description',
        'location_selections',
        'rounds',
        'offer',
        'from_date',
        'to_date',
        'payment_status',
        'stripe_payment_intent_id',
        'paid_at',
        'amount_paid',
        'thumbnail',
        'view_count',
        'active',
        'rounds_completed',
        'campaign_completed',
        'target_reached_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'rounds' => 'integer',
        'amount_paid' => 'decimal:2',
        'paid_at' => 'datetime',
        'from_date' => 'datetime',
        'to_date' => 'datetime',
        'target_reached_at' => 'datetime',
        'location_selections' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }


    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function watchedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'media_users_watched', 'media_id', 'user_id');
    }

    public function watches(): HasMany
    {
        return $this->hasMany(MediaUserWatched::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(UserAssignment::class);
    }

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_assignments', 'media_id', 'user_id')
            ->withPivot('current')
            ->withTimestamps();
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'media_locations', 'media_id', 'location_id')
            ->withTimestamps();
    }

    public function campaign(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Campaign::class);
    }

    public function codes(): HasMany
    {
        return $this->hasMany(CampaignCode::class);
    }

    public function getWatchCount(): int
    {
        return $this->watches()->count();
    }

    public function hasQuestion(): bool
    {
        return $this->questions()->exists();
    }

    public function shouldTriggerQuiz(int $watchCount): bool
    {
        // With the new model, every valid first-time watch of a media
        // that has a question should trigger a quiz for the user.
        return $this->hasQuestion();
    }

    public function getWatchesPerRound(): int
    {
        return config('services.media.target_views');
    }

    public function isRoundComplete(int $watchCount): bool
    {
        return $watchCount == $this->getWatchesPerRound();
    }

    public function incrementRound(): void
    {
        $this->increment('rounds_completed');
        $this->refresh();
    }

    public function isCampaignComplete(): bool
    {
        return $this->rounds_completed >= $this->rounds;
    }

    public function completeCampaign(): void
    {
        $this->update(['campaign_completed' => true]);
    }

    public function getRouteKeyName()
    {
        return 'id';
    }

    /**
     * Boot the model and register event listeners.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::updated(function (Media $media) {
            // Check if media transitioned from pending to processed state
            $wasPending = $media->getOriginal('path') === 'pending' 
                && $media->getOriginal('disk') === 'pending';
            
            $isProcessed = $media->path !== 'pending' 
                && $media->disk !== 'pending'
                && $media->payment_status === 'paid'
                && $media->active === true;

            // Only dispatch jobs when transitioning from pending to processed
            if ($wasPending && $isProcessed) {
                // Generate thumbnail for video media
//                if ($media->mime_type && str_starts_with($media->mime_type, 'video/')) {
//                    GenerateThumbnailJob::dispatch($media->id)->afterCommit();
//                }

                // Assign media to users only if no location IDs exist
                // If location IDs exist, ProcessMediaUploadJob will dispatch AssignMediaToUsersJob
                $hasLocationIds = $media->locations()->exists();

                if (!$hasLocationIds) {
                    AssignMediaToUsersJob::dispatch($media->id)->afterCommit();
                }

                // Send notifications if creator exists
                if ($media->creator_id) {
                    // Send email notification (push notifications removed for creators - web only)
                    SendEmailNotificationJob::dispatch($media->creator_id, $media->id)->afterCommit();
                }

                Log::info('Dependent jobs dispatched after media processing', [
                    'media_id' => $media->id,
                    'path' => $media->path,
                    'disk' => $media->disk,
                    'payment_status' => $media->payment_status,
                    'has_location_ids' => $hasLocationIds,
                ]);
            }
        });
    }

     /**
     * Get the public or temporary URL for the file.
     */
    public function getFileUrl(): ?string
    {
        if (!$this->path || $this->path === 'pending' || $this->disk === 'pending') {
            return null;
        }

        // If the disk is 'url', return the path directly as it's already a URL
        if ($this->disk === 'url') {
            return $this->path;
        }

        // For testing environment, use local storage
        if (config('app.env') === 'testing') {
            return Storage::disk('public')->url($this->path);
        }

        // For S3 storage
        if ($this->disk === 's3') {
            $cacheKey = 'media_url_' . $this->id . '_' . md5($this->path);
            
            return Cache::remember($cacheKey, 3600, function () {
                try {
                    return Storage::disk('s3')->temporaryUrl($this->path, now()->addHours(24));
                } catch (\Exception $e) {
                    return Storage::disk('s3')->url($this->path);
                }
            });
        }

        // For local public storage
        if ($this->disk === 'public') {
            return Storage::disk('public')->url($this->path);
        }

        return null;
    }

    public function getThumbnailUrl(): ?string
    {
        if (!$this->thumbnail) {
            return null;
        }

        if (config('app.env') === 'testing') {
            return Storage::disk('public')->url($this->thumbnail);
        }

        if ($this->disk === 's3') {
            $cacheKey = 'media_thumbnail_' . $this->id . '_' . md5($this->thumbnail);
            
            return Cache::remember($cacheKey, 3600, function () {
                try {
                    return Storage::disk('s3')->temporaryUrl($this->thumbnail, now()->addHours(24));
                } catch (\Exception $e) {
                    return Storage::disk('s3')->url($this->thumbnail);
                }
            });
        }

        if ($this->disk === 'public') {
            return Storage::disk('public')->url($this->thumbnail);
        }

        return null;
    }

    public function getMediaTypeAttribute(): string
    {
        $mimeType = $this->mime_type;

        if (!$mimeType) {
            return 'other';
        }

        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        return 'other';
    }
}
