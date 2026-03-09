<?php

namespace App\Jobs;

use App\Jobs\SendCampaignCompletionEmailJob;
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
 * Scheduled job to delete completed and expired campaigns from database and storage.
 * 
 * Runs daily to:
 * 1. Find all media where:
 *    - campaign_completed = true OR
 *    - to_date < (now - 24 hours) (UTC) OR
 *    - target_reached_at < (now - 24 hours) (UTC)
 * 2. Send completion email with reason before deletion
 * 3. Delete media files from S3/local storage
 * 4. Delete media records from database
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
            $now = now()->utc();
            $cutoffDate = $now->copy()->subHours(24);
            
            $mediaToDelete = Media::where(function ($query) use ($cutoffDate) {
                $query->where('campaign_completed', true)
                    ->orWhere(function ($q) use ($cutoffDate) {
                        $q->whereNotNull('to_date')
                            ->where('to_date', '<', $cutoffDate);
                    })
                    ->orWhere(function ($q) use ($cutoffDate) {
                        $q->whereNotNull('target_reached_at')
                            ->where('target_reached_at', '<', $cutoffDate);
                    });
            })->get();

            if ($mediaToDelete->isEmpty()) {
                Log::info('No completed or expired campaigns found for deletion');
                return;
            }

            $deletedCount = 0;
            $failedCount = 0;

            foreach ($mediaToDelete as $media) {
                try {
                    $creator = $media->creator;
                    $reason = 'completed';
                    $shouldSendEmail = false;

                    if (!$media->campaign_completed) {
                        if ($media->to_date && $media->to_date < $cutoffDate) {
                            $reason = 'expired';
                            $media->update(['campaign_completed' => true]);
                            $shouldSendEmail = true;
                        } elseif ($media->target_reached_at && $media->target_reached_at < $cutoffDate) {
                            $reason = 'target_reached';
                            $media->update(['campaign_completed' => true]);
                            $shouldSendEmail = true;
                        }
                    }

                    if ($creator && $shouldSendEmail) {
                        SendCampaignCompletionEmailJob::dispatch($creator->id, $media->id, $reason)->afterCommit();

                        Log::info('Sent campaign completion email before deletion', [
                            'media_id' => $media->id,
                            'creator_id' => $creator->id,
                            'reason' => $reason,
                        ]);
                    }
                    
                    DB::transaction(function () use ($media, $mediaService) {
                        $mediaService->delete($media);
                    });

                    $deletedCount++;

                    Log::info("Deleted campaign", [
                        'media_id' => $media->id,
                        'creator_id' => $media->creator_id,
                        'reason' => $reason,
                        'to_date' => $media->to_date?->toIso8601String(),
                        'target_reached_at' => $media->target_reached_at?->toIso8601String(),
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

            Log::info('Delete completed and expired campaigns job finished', [
                'total_found' => $mediaToDelete->count(),
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
