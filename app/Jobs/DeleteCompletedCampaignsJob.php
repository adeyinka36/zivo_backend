<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled job to delete completed campaigns from database and storage.
 * 
 * Runs daily to:
 * 1. Find all media where campaign_completed = true
 * 2. Delete media files from S3/local storage
 * 3. Delete media records from database
 */
class DeleteCompletedCampaignsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Execute the job.
     */
    public function handle(MediaService $mediaService): void
    {
        try {
            $completedMedia = Media::where('campaign_completed', true)->get();

            if ($completedMedia->isEmpty()) {
                Log::info('No completed campaigns found for deletion');
                return;
            }

            $deletedCount = 0;
            $failedCount = 0;

            foreach ($completedMedia as $media) {
                try {
                    DB::transaction(function () use ($media, $mediaService) {
                        $mediaService->delete($media);
                    });

                    $deletedCount++;

                    Log::info('Deleted completed campaign', [
                        'media_id' => $media->id,
                        'user_id' => $media->user_id,
                    ]);
                } catch (\Exception $e) {
                    $failedCount++;
                    Log::error('Failed to delete completed campaign', [
                        'media_id' => $media->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            Log::info('Delete completed campaigns job finished', [
                'total_found' => $completedMedia->count(),
                'deleted_count' => $deletedCount,
                'failed_count' => $failedCount,
            ]);
        } catch (\Exception $e) {
            Log::error('Delete completed campaigns job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
