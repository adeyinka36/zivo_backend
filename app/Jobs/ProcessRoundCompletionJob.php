<?php

namespace App\Jobs;

use App\Models\Media;
use App\Models\MediaUserWatched;
use App\Models\User;
use App\Models\UserAssignment;
use App\Jobs\AssignMediaToUsersJob;
use App\Jobs\SendCampaignCompletionEmailJob;
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
        NotificationService $notificationService
    ): void {
        DB::transaction(function () use ($mediaWatchService, $notificationService) {
            $media = $this->media;
            $watchCount = $media->getWatchCount();

            if (!$media->isRoundComplete($watchCount)) {
                return;
            }

            if (!$media->isCampaignComplete()) {
                $media->incrementRound();
                $this->resetWatchesForRound($media);
                $this->resetAssignmentsForRound($media);
            }

            if ($media->isCampaignComplete()) {
                $wasAlreadyComplete = $media->campaign_completed;
                
                if (!$media->target_reached_at) {
                    $media->update(['target_reached_at' => now()->utc()]);
                    $this->notifyTargetReached($media);
                }
                
                $media->completeCampaign();

                if (!$wasAlreadyComplete) {
                    $this->notifyCampaignCompletion($media, 'completed');
                }
            }
        });
    }

    private function getWatchersForMedia(Media $media): Collection
    {
        $watcherIds = MediaUserWatched::where('media_id', $media->id)
            ->pluck('user_id')
            ->unique();

        return User::whereIn('id', $watcherIds)->get();
    }

    private function resetWatchesForRound(Media $media): void
    {
        MediaUserWatched::where('media_id', $media->id)->delete();
    }

    private function resetAssignmentsForRound(Media $media): void
    {
        $deletedCount = UserAssignment::where('media_id', $media->id)->delete();

        Log::info('Deleted assignments for new round', [
            'media_id' => $media->id,
            'deleted_count' => $deletedCount,
        ]);

        AssignMediaToUsersJob::dispatch($media->id)->afterCommit();

        Log::info('Dispatched assignment job for new round', [
            'media_id' => $media->id,
        ]);
    }

    private function notifyTargetReached(Media $media): void
    {
        $creator = $media->creator;

        if (!$creator) {
            Log::warning('Creator not found for target reached notification', [
                'media_id' => $media->id,
            ]);
            return;
        }

        SendCampaignCompletionEmailJob::dispatch($creator->id, $media->id, 'target_reached')->afterCommit();

        Log::info('Target reached notification sent', [
            'media_id' => $media->id,
            'creator_id' => $creator->id,
        ]);
    }

    private function notifyCampaignCompletion(Media $media, string $reason): void
    {
        $creator = $media->creator;

        if (!$creator) {
            Log::warning('Creator not found for campaign completion notification', [
                'media_id' => $media->id,
            ]);
            return;
        }

        SendCampaignCompletionEmailJob::dispatch($creator->id, $media->id, $reason)->afterCommit();

        Log::info('Campaign completion notifications sent', [
            'media_id' => $media->id,
            'creator_id' => $creator->id,
            'reason' => $reason,
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

