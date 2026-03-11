<?php

namespace App\Jobs;

use App\Models\Creator;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SendEmailVerificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly string $notifiableType,
        private readonly string $notifiableId,
    ) {}

    public static function forUser(string $userId): self
    {
        return new self(User::class, $userId);
    }

    public static function forCreator(string $creatorId): self
    {
        return new self(Creator::class, $creatorId);
    }

    public function handle(): void
    {
        $notifiable = match ($this->notifiableType) {
            User::class => User::find($this->notifiableId),
            Creator::class => Creator::find($this->notifiableId),
            default => null,
        };

        if (! $notifiable) {
            Log::warning('SendEmailVerificationJob: Notifiable not found or unsupported', [
                'notifiable_type' => $this->notifiableType,
                'notifiable_id' => $this->notifiableId,
            ]);
            return;
        }

        try {
            $notifiable->sendEmailVerificationNotification();
        } catch (\Exception $e) {
            Log::error('SendEmailVerificationJob: Failed to send verification email', [
                'notifiable_type' => $this->notifiableType,
                'notifiable_id' => $this->notifiableId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SendEmailVerificationJob failed permanently', [
            'notifiable_type' => $this->notifiableType,
            'notifiable_id' => $this->notifiableId,
            'error' => $exception->getMessage(),
        ]);
    }
}

