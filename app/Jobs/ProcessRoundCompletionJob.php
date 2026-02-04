<?php

namespace App\Jobs;

use App\Models\Media;
use App\Models\MediaUserWatched;
use App\Models\User;
use App\Models\UserAssignment;
use App\Services\MediaAssignmentService;
use App\Services\MediaWatchService;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessRoundCompletionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly Media $media
    ) {}

    public function handle(
        MediaWatchService $mediaWatchService,
        MediaAssignmentService $assignmentService,
        NotificationService $notificationService
    ): void {
        DB::transaction(function () use ($mediaWatchService, $assignmentService, $notificationService) {
            $media = $this->media;
            $watchCount = $media->getWatchCount();

            if (!$media->isRoundComplete($watchCount)) {
                return;
            }

            if (!$media->isCampaignComplete()) {
                $media->incrementRound();
                $this->resetWatchesForRound($media);
                $this->resetAssignmentsForRound($media, $assignmentService);
            }

            if ($media->isCampaignComplete()) {
                $wasAlreadyComplete = $media->campaign_completed;
                $media->completeCampaign();

                if (!$wasAlreadyComplete) {
                    $this->notifyCampaignCompletion($media, $notificationService);
                }
            }
        });
    }

    private function getWatchersForMedia(Media $media): Collection
    {
        $watcherIds = MediaUserWatched::where('media_id', $media->id)
            ->where('user_id', '!=', $media->user_id)
            ->pluck('user_id')
            ->unique();

        return User::whereIn('id', $watcherIds)->get();
    }

    private function resetWatchesForRound(Media $media): void
    {
        MediaUserWatched::where('media_id', $media->id)->delete();
    }

    private function resetAssignmentsForRound(Media $media, MediaAssignmentService $assignmentService): void
    {
        $deletedCount = UserAssignment::where('media_id', $media->id)->delete();

        Log::info('Deleted assignments for new round', [
            'media_id' => $media->id,
            'deleted_count' => $deletedCount,
        ]);

        $assignedCount = $assignmentService->assignMediaToUsers($media);

        Log::info('Reassigned media for new round', [
            'media_id' => $media->id,
            'assigned_count' => $assignedCount,
        ]);
    }

    private function notifyCampaignCompletion(Media $media, NotificationService $notificationService): void
    {
        $uploader = $media->user;

        if (!$uploader) {
            Log::warning('Uploader not found for campaign completion notification', [
                'media_id' => $media->id,
            ]);
            return;
        }

        $notificationService->sendCampaignCompletionNotification($uploader, $media);

        Log::info('Campaign completion notification sent', [
            'media_id' => $media->id,
            'uploader_id' => $uploader->id,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ProcessRoundCompletionJob failed permanently', [
            'media_id' => $this->media->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}

