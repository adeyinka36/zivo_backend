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
     */
    public function handle(MediaAssignmentService $assignmentService): void
    {
        $cutoffTime = now()->subHours(self::ASSIGNMENT_WINDOW_HOURS);

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

        if ($staleAssignments->isEmpty()) {
            Log::info('No stale assignments found for cleanup');
            return;
        }

        $mediaToReassign = $staleAssignments->groupBy('media_id');
        $deletedCount = 0;

        foreach ($mediaToReassign as $mediaId => $assignments) {
            $media = Media::with(['locations', 'creator'])->find($mediaId);

            if (!$media) {
                continue;
            }

            $assignmentIds = $assignments->pluck('id')->toArray();

            DB::transaction(function () use ($assignmentIds, $media, $assignmentService, &$deletedCount) {
                UserAssignment::whereIn('id', $assignmentIds)->delete();
                $deletedCount += count($assignmentIds);

                Log::info('Deleted stale assignments for media', [
                    'media_id' => $media->id,
                    'deleted_count' => count($assignmentIds),
                ]);

                $assignmentService->assignMediaToUsers($media);
            });
        }

        Log::info('Cleanup and reassignment completed', [
            'total_deleted' => $deletedCount,
            'media_processed' => $mediaToReassign->count(),
        ]);
    }
}
