<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Facades\Storage;

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
        'payment_status',
        'stripe_payment_intent_id',
        'paid_at',
        'amount_paid',
        'quiz_played',
    ];

    protected $casts = [
        'size' => 'integer',
        'reward' => 'integer',
        'amount_paid' => 'decimal:2',
        'paid_at' => 'datetime'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
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
            try {
                // Try to get a temporary URL (valid for 1 hour)
                return Storage::disk('s3')->temporaryUrl($this->path, now()->addHour());
            } catch (\Exception $e) {
                // Fallback to regular URL if temporaryUrl fails
                return Storage::disk('s3')->url($this->path);
            }
        }

        // For local public storage
        if ($this->disk === 'public') {
            return Storage::disk('public')->url($this->path);
        }

        return null;
    }
}
