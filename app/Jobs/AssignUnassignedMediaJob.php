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
 * Scheduled job to assign users to media that have no assignments.
 * 
 * Runs every 6 hours to:
 * 1. Find all media where:
 *    - No UserAssignment records exist
 *    - Campaign is not complete
 *    - Payment status is 'paid'
 *    - Active is true
 *    - Media is uploaded (not pending)
 * 2. Assign users to these media using MediaAssignmentService
 */
class AssignUnassignedMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Execute the job.
     */
    public function handle(MediaAssignmentService $assignmentService): void
    {
        try {
            $unassignedMedia = Media::query()
                ->where('campaign_completed', false)
                ->where('payment_status', 'paid')
                ->where('active', true)
                ->where('mime_type', '!=', 'pending')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('user_assignments')
                        ->whereColumn('user_assignments.media_id', 'media.id');
                })
                ->with(['locations', 'user'])
                ->get();

            if ($unassignedMedia->isEmpty()) {
                Log::info('AssignUnassignedMediaJob: No unassigned media found');
                return;
            }

            $processedCount = 0;
            $failedCount = 0;

            foreach ($unassignedMedia as $media) {
                try {
                    $assignedCount = $assignmentService->assignMediaToUsers($media);

                    Log::info('AssignUnassignedMediaJob: Assigned users to media', [
                        'media_id' => $media->id,
                        'assigned_count' => $assignedCount,
                    ]);

                    $processedCount++;
                } catch (\Exception $e) {
                    $failedCount++;
                    Log::error('AssignUnassignedMediaJob: Failed to assign users to media', [
                        'media_id' => $media->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            Log::info('AssignUnassignedMediaJob completed', [
                'total_found' => $unassignedMedia->count(),
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
