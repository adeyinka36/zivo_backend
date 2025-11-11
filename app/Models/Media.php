<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Models\MediaUserWatched;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class Media extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'name',
        'file_name',
        'mime_type',
        'size',
        'path',
        'disk',
        'description',
        'reward',
        'rounds',
        'payment_status',
        'stripe_payment_intent_id',
        'paid_at',
        'amount_paid',
        'thumbnail',
        'quiz_played',
        'quiz_number',
        'view_count',
        'active',
        'rounds_completed',
        'campaign_completed',
    ];

    protected $casts = [
        'size' => 'integer',
        'reward' => 'integer',
        'rounds' => 'integer',
        'amount_paid' => 'decimal:2',
        'paid_at' => 'datetime'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
        return $this->belongsToMany(User::class, 'media_users_watched', 'media_id', 'user_id')
            ->where('media_users_watched.user_id', '!=', $this->user_id);
    }

    public function watches(): HasMany
    {
        return $this->hasMany(MediaUserWatched::class);
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
        Log::info('should not trigger quiz--',[
            'watch_count' => $watchCount,
            'quiz_number' => $this->quiz_number,
            'has_question' => $this->hasQuestion(),
            'trigger_quiz' => $this->hasQuestion() && $this->quiz_number == $watchCount
        ]);
        return $this->hasQuestion() && $this->quiz_number == $watchCount;
    }

    public function getWatchesPerRound(): int
    {
        return config('services.media.target_views');
    }

    public function isRoundComplete(int $watchCount): bool
    {
        return $watchCount > $this->getWatchesPerRound();
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
     * Get the public or temporary URL for the file.
     */
    public function getFileUrl(): ?string
    {
        if (!$this->path) {
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
