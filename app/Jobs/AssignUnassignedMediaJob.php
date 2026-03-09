<?php

namespace App\Jobs;

use App\Jobs\AssignMediaToUsersJob;
use App\Models\Campaign;
use App\Models\Media;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled job to manage media activation and assignment.
 * 
 * Runs every 30 minutes to:
 * 1. Activate media when from_date is reached (UTC)
 * 2. Find all media where:
 *    - Campaign is not complete
 *    - Payment status is 'paid'
 *    - Active is true
 *    - Media is uploaded (not pending)
 *    - Within date range (from_date <= now <= to_date)
 *    - Current round needs assignments
 * 3. Dispatch AssignMediaToUsersJob for each eligible media
 */
class AssignUnassignedMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(): void
    {
        try {
            $now = now()->utc();
            
            $activatedMedia = Media::query()
                ->where('campaign_completed', false)
                ->where('payment_status', 'paid')
                ->where('mime_type', '!=', 'pending')
                ->whereNotNull('from_date')
                ->whereNotNull('to_date')
                ->where('from_date', '<=', $now)
                ->where('to_date', '>=', $now)
                ->where('active', false)
                ->get();
            
            foreach ($activatedMedia as $media) {
                $media->update(['active' => true]);
                
                // Update campaign active status
                Campaign::where('media_id', $media->id)->update(['active' => true]);
            }
            
            $targetViews = (int) config('services.media.target_views', 10);
            
            $eligibleMedia = Media::query()
                ->where('campaign_completed', false)
                ->where('payment_status', 'paid')
                ->where('active', true)
                ->where('mime_type', '!=', 'pending')
                ->whereNotNull('from_date')
                ->whereNotNull('to_date')
                ->where('from_date', '<=', $now)
                ->where('to_date', '>=', $now)
                ->whereRaw('rounds_completed < rounds')
                ->with(['locations', 'creator'])
                ->get()
                ->filter(function ($media) use ($targetViews) {
                    $currentRound = $media->rounds_completed + 1;
                    $watchedCount = $media->watchedByUsers()->count();
                    $roundsCompleted = (int) floor($watchedCount / $targetViews);
                    
                    if ($roundsCompleted >= $currentRound) {
                        return false;
                    }
                    
                    $currentCount = $media->assignments()->where('current', true)->count();
                    $neededCount = max(0, $targetViews - $currentCount);
                    
                    return $neededCount > 0;
                });

            if ($eligibleMedia->isEmpty()) {
                Log::info('AssignUnassignedMediaJob: No eligible media found');
                return;
            }

            $processedCount = 0;
            $failedCount = 0;

            foreach ($eligibleMedia as $media) {
                try {
                    AssignMediaToUsersJob::dispatch($media->id)->afterCommit();

                    Log::info('AssignUnassignedMediaJob: Dispatched assignment job for media', [
                        'media_id' => $media->id,
                    ]);

                    $processedCount++;
                } catch (\Exception $e) {
                    $failedCount++;
                    Log::error('AssignUnassignedMediaJob: Failed to dispatch assignment job', [
                        'media_id' => $media->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            Log::info('AssignUnassignedMediaJob completed', [
                'total_found' => $eligibleMedia->count(),
                'processed_count' => $processedCount,
                'failed_count' => $failedCount,
            ]);
        } catch (\Exception $e) {
            Log::error('AssignUnassignedMediaJob failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
