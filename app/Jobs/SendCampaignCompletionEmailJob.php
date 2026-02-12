<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Media;
use App\Notifications\CampaignCompletionNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job for sending email notifications asynchronously when a campaign completes.
 * 
 * This job is dispatched when a campaign is completed,
 * ensuring that email notification failures do not block the campaign completion process.
 */
class SendCampaignCompletionEmailJob implements ShouldQueue
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
            Log::warning('User or media not found for campaign completion email notification', [
                'user_id' => $this->userId,
                'media_id' => $this->mediaId
            ]);
            return;
        }

        try {
            $user->notify(new CampaignCompletionNotification($media));
            
            Log::info('Campaign completion email notification sent successfully', [
                'user_id' => $this->userId,
                'media_id' => $this->mediaId
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send campaign completion email notification', [
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
        Log::error('SendCampaignCompletionEmailJob failed permanently', [
            'user_id' => $this->userId,
            'media_id' => $this->mediaId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
