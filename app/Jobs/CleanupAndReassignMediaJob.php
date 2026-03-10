<?php

namespace App\Jobs;

use App\Models\Media;
use App\Models\UserAssignment;
use App\Services\MediaAssignmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled job to cleanup stale media assignments and reassign.
 *
 * Runs every 6 hours to:
 * 1. Find assignments that are unwatched, current, and older than 6 hours
 * 2. Delete those stale assignments
 * 3. Reassign media to reach the target count
 */
class CleanupAndReassignMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Number of hours a user has to watch assigned media before it is reassigned. */
    public const ASSIGNMENT_WINDOW_HOURS = 6;

    public int $tries = 3;

    /**
     * Execute the job.
     *
     * 1. Delete stale, unwatched current assignments.
     * 2. For all eligible media, ensure assignments are topped up to the target
     *    for the current round (via MediaAssignmentService).
     */
    public function handle(MediaAssignmentService $assignmentService): void
    {
        $cutoffTime = now()->subHours(self::ASSIGNMENT_WINDOW_HOURS);

        // Step 1: remove stale current assignments (older than window, never watched)
        $staleAssignments = UserAssignment::where('current', true)
            ->where('created_at', '<', $cutoffTime)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('media_users_watched')
                    ->whereColumn('media_users_watched.user_id', 'user_assignments.user_id')
                    ->whereColumn('media_users_watched.media_id', 'user_assignments.media_id');
            })
            ->with('media')
            ->get();

        $mediaWithStale = $staleAssignments->groupBy('media_id');
        $deletedCount = 0;

        foreach ($mediaWithStale as $mediaId => $assignments) {
            $media = Media::find($mediaId);

            if (! $media) {
                continue;
            }

            $assignmentIds = $assignments->pluck('id')->toArray();

            DB::transaction(function () use ($assignmentIds, $media, &$deletedCount) {
                UserAssignment::whereIn('id', $assignmentIds)->delete();
                $deletedCount += count($assignmentIds);

                Log::info('Deleted stale assignments for media', [
                    'media_id' => $media->id,
                    'deleted_count' => count($assignmentIds),
                ]);
            });
        }

        // Step 2: for all eligible media, top up assignments to target views
        $now = now()->utc();

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
            ->get();

        $toppedUpCount = 0;

        foreach ($eligibleMedia as $media) {
            $assignmentService->assignMediaToUsers($media);
            $toppedUpCount++;
        }

        Log::info('CleanupAndReassignMediaJob completed', [
            'stale_deleted' => $deletedCount,
            'media_with_stale' => $mediaWithStale->count(),
            'media_topped_up' => $toppedUpCount,
        ]);
    }
}
