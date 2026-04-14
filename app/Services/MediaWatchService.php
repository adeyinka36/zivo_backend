<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use App\Models\MediaUserWatched;
use App\Models\UserAssignment;
use App\Http\Resources\MediaWithQuestionResource;
use App\Jobs\AllocateRewardWithAmount;
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
            
            $watchRecord = $this->recordWatch($media, $user);
            $watchCount = $media->getWatchCount();
            
            $quizData = $this->handleQuizTrigger($media, $user, $watchCount, $watchRecord['was_new']);

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
     * @return array{was_new: bool, recorded: bool}
     */
    private function recordWatch(Media $media, User $user): array
    {
        $hasAssignment = UserAssignment::where('user_id', $user->id)
            ->where('media_id', $media->id)
            ->where('current', true)
            ->exists();

        if (!$hasAssignment) {
            Log::warning('Watch attempt by unassigned user', [
                'user_id' => $user->id,
                'media_id' => $media->id,
            ]);

            return [
                'was_new' => false,
                'recorded' => false,
            ];
        }

        $watchRecord = MediaUserWatched::firstOrCreate([
            'user_id' => $user->id,
            'media_id' => $media->id,
        ]);

        $wasNewRecord = $watchRecord->wasRecentlyCreated;

        return [
            'was_new' => $wasNewRecord,
            'recorded' => true,
        ];
    }

    /**
     * Handle quiz trigger logic.
     *
     * @param Media $media
     * @param User $user
     * @param int $watchCount
     * @param bool $wasNewWatch
     * @return MediaWithQuestionResource|null
     */
    private function handleQuizTrigger(
        Media $media,
        User $user,
        int $watchCount,
        bool $wasNewWatch
    ): ?MediaWithQuestionResource {
        if (!$wasNewWatch) {
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

    // Legacy reward allocation for unwon rounds has been removed.
    // The new model no longer distributes monetary rewards; users
    // unlock the creator's offer via email when they answer correctly.

    /**
     * Get all watchers for a media item.
     *
     * @param Media $media
     * @return \Illuminate\Support\Collection
     */
    private function getWatchersForMedia(Media $media)
    {
        $watcherIds = MediaUserWatched::where('media_id', $media->id)
            ->pluck('user_id')
            ->unique();

        return User::whereIn('id', $watcherIds)->get();
    }
}
