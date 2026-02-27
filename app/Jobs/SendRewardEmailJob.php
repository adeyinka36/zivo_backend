<?php

namespace App\Jobs;

use App\Models\Media;
use App\Models\User;
use App\Notifications\RewardEmailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendRewardEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly string $userId,
        private readonly string $mediaId,
        private readonly ?string $code = null
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);
        $media = Media::with(['creator', 'locations'])->find($this->mediaId);

        if (!$user || !$media) {
            Log::warning('SendRewardEmailJob: User or media not found', [
                'user_id' => $this->userId,
                'media_id' => $this->mediaId,
            ]);
            return;
        }

        try {
            $user->notify(new RewardEmailNotification($media, $this->code));

            Log::info('SendRewardEmailJob: Reward email sent', [
                'user_id' => $user->id,
                'media_id' => $media->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('SendRewardEmailJob: Failed to send reward email', [
                'user_id' => $user->id,
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

