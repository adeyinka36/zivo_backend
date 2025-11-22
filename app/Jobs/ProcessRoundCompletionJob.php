<?php

namespace App\Jobs;

use App\Models\Media;
use App\Models\MediaUserWatched;
use App\Models\User;
use App\Services\MediaWatchService;
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

    public function handle(MediaWatchService $mediaWatchService): void
    {

        DB::transaction(function () use ($mediaWatchService) {
            $media = $this->media;
            $watchCount = $media->getWatchCount();

            if (!$media->isRoundComplete($watchCount)) {
                return;
            }

            $watchers = $this->getWatchersForMedia($media);

            if (
                    !$media->isCampaignComplete() &&
                    $watchers->isNotEmpty() &&
                    (count($watchers) > config('services.media.target_views')) &&
                    !$media->prize_won &&
                    !$media->prize_distributed
            ) {
                $mediaWatchService->allocateRewardsForUnwonRound($media, $watchers);
            }


            if (!$media->isCampaignComplete()) {
                $media->incrementRound();
                $this->resetWatchesForRound($media);
            }

            if ($media->isCampaignComplete()) {
                $media->completeCampaign();
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

    public function failed(Throwable $exception): void
    {
        Log::error('ProcessRoundCompletionJob failed permanently', [
            'media_id' => $this->mediaId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}

