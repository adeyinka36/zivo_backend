<?php

namespace App\Jobs;

use App\Jobs\ProcessRoundCompletionJob;
use App\Models\Media;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled job to check for media with completed rounds and process them.
 * 
 * Runs every 6 hours to:
 * 1. Find all media where watch count equals target_views
 * 2. Exclude media where campaign is already complete
 * 3. Dispatch ProcessRoundCompletionJob for each eligible media
 */
class CheckRoundCompletionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $targetViews = (int) config('services.media.target_views', 25);

            $mediaWithCompletedRounds = Media::query()
                ->where('campaign_completed', false)
                ->whereColumn('rounds_completed', '<', 'rounds')
                ->whereRaw('(
                    SELECT COUNT(*)
                    FROM media_users_watched
                    WHERE media_users_watched.media_id = media.id
                ) = ?', [$targetViews])
                ->get();

            $processedCount = 0;

            foreach ($mediaWithCompletedRounds as $media) {
                $watchCount = $media->getWatchCount();
                
                if ($media->isRoundComplete($watchCount)) {
                    ProcessRoundCompletionJob::dispatch($media);
                    $processedCount++;
                }
            }

            if ($processedCount > 0) {
                Log::info('CheckRoundCompletionJob completed', [
                    'media_processed' => $processedCount,
                    'target_views' => $targetViews,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('CheckRoundCompletionJob failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
