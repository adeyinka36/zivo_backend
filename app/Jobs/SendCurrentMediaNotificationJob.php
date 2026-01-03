<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Media;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job for sending push notifications when media becomes current or is removed as current
 */
class SendCurrentMediaNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly string $userId,
        private readonly string $mediaId,
        private readonly string $type
    ) {}

    /**
     * Execute the job
     */
    public function handle(NotificationService $notificationService): void
    {
        $user = User::find($this->userId);
        $media = Media::find($this->mediaId);

        if (!$user || !$media) {
            Log::warning('User or media not found for current media notification', [
                'user_id' => $this->userId,
                'media_id' => $this->mediaId,
                'type' => $this->type
            ]);
            return;
        }

        if ($this->type === 'assigned') {
            $notificationService->sendCurrentMediaAssigned($user, $media);
        } elseif ($this->type === 'removed') {
            $notificationService->sendCurrentMediaRemoved($user, $media);
        }
    }

    /**
     * Handle job failure
     */
    public function failed(Throwable $exception): void
    {
        Log::error('SendCurrentMediaNotificationJob failed permanently', [
            'user_id' => $this->userId,
            'media_id' => $this->mediaId,
            'type' => $this->type,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
