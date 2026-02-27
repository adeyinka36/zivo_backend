<?php

namespace App\Jobs;

use App\Models\Creator;
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
        private readonly string $creatorId,
        private readonly string $mediaId,
        private readonly string $reason = 'completed'
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $creator = Creator::find($this->creatorId);
        $media = Media::find($this->mediaId);

        if (!$creator || !$media) {
            Log::warning('Creator or media not found for campaign completion email notification', [
                'creator_id' => $this->creatorId,
                'media_id' => $this->mediaId
            ]);
            return;
        }

        try {
            $creator->notify(new CampaignCompletionNotification($media, $this->reason));
            
            Log::info('Campaign completion email notification sent successfully', [
                'creator_id' => $this->creatorId,
                'media_id' => $this->mediaId,
                'reason' => $this->reason,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send campaign completion email notification', [
                'creator_id' => $this->creatorId,
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
            'creator_id' => $this->creatorId,
            'media_id' => $this->mediaId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
