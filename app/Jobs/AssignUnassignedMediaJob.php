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
 * Scheduled job to manage media activation.
 *
 * Runs every 30 minutes to:
 * 1. Activate media when from_date is reached (UTC)
 * 2. Mark the corresponding campaign as active
 *
 * Assignment and top-up logic are handled by other jobs
 * (Media updated event, ProcessRoundCompletionJob, CleanupAndReassignMediaJob),
 * so this job no longer dispatches AssignMediaToUsersJob.
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

            Log::info('AssignUnassignedMediaJob: activation pass completed', [
                'activated_count' => $activatedMedia->count(),
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
