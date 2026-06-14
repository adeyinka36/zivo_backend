<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasUuid, CanResetPassword;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'email_verified_at',
        'password',
        'google_id',
        'google_token',
        'google_refresh_token',
        'apple_id',
        'avatar',
        'push_token',
        'push_notifications',
        'active',
        'latitude',
        'longitude',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'google_token',
        'google_refresh_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'push_notifications' => 'boolean',
        'active' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    /**
     * Send the password reset notification.
     *
     * @param  string  $token
     * @return void
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }

    public function hasVerifiedEmail(): bool
    {
        return filled($this->google_id)
            || filled($this->apple_id)
            || ! is_null($this->email_verified_at);
    }

    public function emailVerifiedAtIso(): ?string
    {
        $verifiedAt = $this->email_verified_at
            ?? ((filled($this->google_id) || filled($this->apple_id)) ? $this->created_at : null);

        return $verifiedAt?->toISOString();
    }

    public function socialSignInRequiredMessage(): ?string
    {
        if (filled($this->apple_id)) {
            return \App\Exceptions\Auth\AppleSignInRequiredException::MESSAGE;
        }

        if (filled($this->google_id)) {
            return \App\Exceptions\Auth\GoogleSignInRequiredException::MESSAGE;
        }

        return null;
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(UserAssignment::class);
    }

    public function assignedMedia(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'user_assignments', 'user_id', 'media_id')
            ->withPivot('current')
            ->withTimestamps();
    }

}
