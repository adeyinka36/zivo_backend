<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use App\Models\MediaUserWatched;
use App\Http\Resources\MediaWithQuestionResource;
use App\Jobs\AllocateRewardWithAmount;
use App\Jobs\ProcessRoundCompletionJob;
use Illuminate\Support\Collection;
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

            if ($media->isRoundComplete($watchCount)) {
                ProcessRoundCompletionJob::dispatch($media);
            }

            
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
//        $recorded = $isEmailVerified && $wasNewRecord;

        return [
            'was_new' => $wasNewRecord,
            'recorded' => $isEmailVerified,
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
        //throw error if reward less than 100?

        $reward = $media->reward;
        $numberOfPeopleInRound = config('services.media.target_views');
        $winnersCount = 0;
        $winningAmount = 0;

        if ($reward == 100) {
            $winnersCount = 1;
            $winningAmount = 100;
        } elseif ($reward >= (config('services.media.target_views') * 100)) {
            $reward -= ($reward % config('services.media.target_views') * 100);
            $winningAmount = (int) ($reward / config('services.media.target_views'));
            $winnersCount = $numberOfPeopleInRound;
        } elseif ($reward > 100) {
            $reward -= $reward % 100;
            $split = (int) $reward/100;
            $winnersCount = $split < config('services.media.target_views') ? $split : config('services.media.target_views');
            $winningAmount = 100;
        }

        $availableWatchers = $watchers->count();
        
        if ($availableWatchers === 0 || !$winnersCount || !$winningAmount) {
            Log::warning('No reward allocated for this unwon round something is wrong!', [
                'media_id' => $media->id,
                'reward' => $reward,
                'winners' => $availableWatchers,
                'winning_amount' => $winningAmount,
                'winners_count' => $winnersCount,
            ]);
            return;
        }

        // Sometimes Allocating not to watchers of the media but to random active, verified users of the app. This show discourage fraud and rewards being a member of the platform
        $randomActiveUsersToReward = User::whereNotNull('push_token')
            ->whereNotNull('email_verified_at')
            ->inRandomOrder()
            ->limit($winnersCount)
            ->get();

        if($randomActiveUsersToReward->count() < $winnersCount){
            $selectedWinners = $watchers->take($winnersCount);
        }
        else{
            $selectedWinners = $randomActiveUsersToReward;
        }


        Log::info('Selected winners for unwon round --->', $selectedWinners->pluck('email')->toArray());

        $totalPaymentMade = 0;

        foreach ($selectedWinners as $winner) {
            if($winner->email_verified_at) {
                $totalPaymentMade += $winningAmount;
                if($totalPaymentMade > $reward){
                    Log::info('Amount being paid is more than reward',  [
                        'media_id' => $media->id,
                        'reward' => $reward,
                        'winners' => $availableWatchers,
                        'winning_amount' => $winningAmount,
                        'winners_count' => $winnersCount,
                        'total_payment_made' => $totalPaymentMade,
                    ]);
                    break;
                }
                AllocateRewardWithAmount::dispatch($winner, $winningAmount);
                sleep(2);
            }
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
