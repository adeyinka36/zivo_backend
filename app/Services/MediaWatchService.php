<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use App\Models\MediaUserWatched;
use App\Http\Resources\MediaWithQuestionResource;
use App\Jobs\AllocateRewardWithAmount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MediaWatchService
{
    /**
     * Mark media as watched for a user.
     *
     * @param Media $media
     * @param User $user
     * @return array<string, mixed>
     */
    public function markAsWatched(Media $media, User $user): array
    {
        return DB::transaction(function () use ($media, $user) {
            $isEmailVerified = $user->hasVerifiedEmail();
            
            $watchRecord = $this->recordWatch($media, $user, $isEmailVerified);
            $watchCount = $media->getWatchCount();
            
            $quizData = $this->handleQuizTrigger($media, $user, $watchCount, $isEmailVerified, $watchRecord['was_new']);
            
            $this->handleRoundCompletion($media, $watchCount);
            
            $media->refresh();
            
            return [
                'watch_recorded' => $watchRecord['recorded'],
                'email_verified' => $isEmailVerified,
                'trigger_quiz' => $quizData !== null,
                'quiz_data' => $quizData,
            ];
        });
    }

    /**
     * Record a watch for the user.
     *
     * @param Media $media
     * @param User $user
     * @param bool $isEmailVerified
     * @return array{was_new: bool, recorded: bool}
     */
    private function recordWatch(Media $media, User $user, bool $isEmailVerified): array
    {
        $watchRecord = MediaUserWatched::firstOrCreate([
            'user_id' => $user->id,
            'media_id' => $media->id,
        ]);

        $wasNewRecord = $watchRecord->wasRecentlyCreated;
        $recorded = $isEmailVerified && $wasNewRecord;

        return [
            'was_new' => $wasNewRecord,
            'recorded' => $recorded,
        ];
    }

    /**
     * Handle quiz trigger logic.
     *
     * @param Media $media
     * @param User $user
     * @param int $watchCount
     * @param bool $isEmailVerified
     * @param bool $wasNewWatch
     * @return MediaWithQuestionResource|null
     */
    private function handleQuizTrigger(
        Media $media,
        User $user,
        int $watchCount,
        bool $isEmailVerified,
        bool $wasNewWatch
    ): ?MediaWithQuestionResource {
        if (!$isEmailVerified || !$wasNewWatch) {
            return null;
        }

        if (!$media->shouldTriggerQuiz($watchCount)) {
            return null;
        }

        return new MediaWithQuestionResource($media);
    }

    /**
     * Handle round completion logic.
     *
     * @param Media $media
     * @param int $watchCount
     * @return void
     */
    private function handleRoundCompletion(Media $media, int $watchCount): void
    {
        if (!$media->isRoundComplete($watchCount)) {
            return;
        }

        $media->incrementRound();

        if ($media->isCampaignComplete()) {
            $media->completeCampaign();
        }

        $watchers = $this->getWatchersForMedia($media);
        
        if ($watchers->isNotEmpty()) {
            $this->allocateRewardsForUnwonRound($media, $watchers);
        }

        if (!$media->isCampaignComplete()) {
            $this->resetWatchesForRound($media);
        }

    }

    /**
     * Reset watches when a round is completed.
     *
     * @param Media $media
     * @return void
     */
    private function resetWatchesForRound(Media $media): void
    {
        MediaUserWatched::where('media_id', $media->id)->delete();
    }

    /**
     * Allocate rewards for a round that completed without a winner.
     *
     * @param Media $media
     * @param Collection $watchers
     * @return void
     */
    public function allocateRewardsForUnwonRound(Media $media,Collection $watchers): void
    {
        $reward = $media->reward;
        $numberOfPeopleInRound = 100;

        if ($reward == 100) {
            $winnersCount = 1;
            $winningAmount = 100;
        } elseif ($reward >= 10000) {
            $reward -= $reward % 10000;
            $winningAmount = (int) ($reward / 100);
            $winnersCount = $numberOfPeopleInRound;
        } elseif ($reward >= 100) {
            $reward -= $reward % 100;
            $winnersCount = (int) ($reward / 100);
            $winningAmount = 100;
        } else {
            return;
        }

        $availableWatchers = $watchers->count();
        
        if ($availableWatchers === 0) {
            Log::warning('No watchers available for reward allocation', [
                'media_id' => $media->id,
            ]);
            return;
        }

        $selectedWinners = $watchers->random($winnersCount);

        foreach ($selectedWinners as $winner) {
            AllocateRewardWithAmount::dispatch($winner, $winningAmount);
        }

        Log::info('Rewards allocated for unwon round', [
            'media_id' => $media->id,
            'winners_count' => $winnersCount,
            'winning_amount' => $winningAmount,
            'total_amount' => $winnersCount * $winningAmount,
        ]);
    }

    /**
     * Get all watchers for a media item.
     *
     * @param Media $media
     * @return \Illuminate\Support\Collection
     */
    private function getWatchersForMedia(Media $media)
    {
        $watcherIds = MediaUserWatched::where('media_id', $media->id)
            ->where('user_id', '!=', $media->user_id)
            ->pluck('user_id')
            ->unique();

        return User::whereIn('id', $watcherIds)->get();
    }
}
