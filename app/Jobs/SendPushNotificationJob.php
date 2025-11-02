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
 * Job for sending push notifications asynchronously
 */
class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly string $userId,
        private readonly string $mediaId
    ) {}

    /**
     * Execute the job
     * @throws \Exception
     */
    public function handle(NotificationService $notificationService): void
    {
        $user = User::find($this->userId);
        $media = Media::find($this->mediaId);

        if (!$user || !$media) {
            Log::warning('User or media not found for push notification', [
                'user_id' => $this->userId,
                'media_id' => $this->mediaId
            ]);
            return;
        }

        $success = $notificationService->sendMediaUploadSuccess($user, $media);

        if (!$success) {
            throw new \Exception('Failed to send push notification');
        }

        // Send notification to all subscribed users about new media upload
        $notificationService->sendNotificationOfUploadedMediaToSubscribed($user, $media);
    }

    /**
     * Handle job failure
     */
    public function failed(Throwable $exception): void
    {
        Log::error('SendPushNotificationJob failed permanently', [
            'user_id' => $this->userId,
            'media_id' => $this->mediaId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
