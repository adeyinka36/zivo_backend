<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Media;
use App\Notifications\MediaUploadSuccessNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job for sending email notifications asynchronously after media upload completes.
 * 
 * This job is dispatched after the media upload is successfully processed,
 * ensuring that email notification failures do not block the upload process.
 */
class SendEmailNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly string $userId,
        private readonly string $mediaId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $user = User::find($this->userId);
        $media = Media::find($this->mediaId);

        if (!$user || !$media) {
            Log::warning('User or media not found for email notification', [
                'user_id' => $this->userId,
                'media_id' => $this->mediaId
            ]);
            return;
        }

        try {
            $user->notify(new MediaUploadSuccessNotification($media));
            
            Log::info('Email notification sent successfully', [
                'user_id' => $this->userId,
                'media_id' => $this->mediaId
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send email notification', [
                'user_id' => $this->userId,
                'media_id' => $this->mediaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('SendEmailNotificationJob failed permanently', [
            'user_id' => $this->userId,
            'media_id' => $this->mediaId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
