<?php

namespace App\Jobs;

use App\Http\Resources\MediaWithQuestionResource;
use App\Models\Media;
use App\Models\User;
use App\Services\SendNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class QuizInvitation implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(private readonly Media $media)
    {
        //
    }

    /**
     * Execute the job.
     * @throws ConnectionException
     */
    public function handle(): void
    {
        $reward = ($this->media->reward)/100;
        $peopleWhoWatched = $this->media->watchedByUsers();
        $userTokens  = $peopleWhoWatched->pluck('push_token')->toArray();
        if (empty($userTokens)) {
            Log::info("No push token for player who watched media: {$this->media->id}");
            return;
        }
        $selectedUser = array_rand($userTokens, 1);

        $title = 'Quiz Invitation';
        $body = "You have been invited to participate in a quiz worth \$$reward in AWS voucher.";
        $data = [
            'media' => new MediaWithQuestionResource($this->media),
            'type' => 'quiz_invitation',
        ];

        SendNotification::toExpoNotification($selectedUser, $title, $body, $data);
    }
}
