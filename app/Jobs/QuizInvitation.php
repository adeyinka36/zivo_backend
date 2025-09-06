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
    public function __construct(private readonly Media $media, private readonly User $winner)
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
        $winnerUserToken  = $this->winner->push_token;

        $title = 'Quiz Invitation';
        $body = "You have been invited to participate in a quiz worth \$$reward in AWS voucher.";
        $data = [
            'media' => new MediaWithQuestionResource($this->media),
            'type' => 'quiz_invitation',
        ];

        Log::info("Sending quiz invitation to user {$this->winner->id} with token-----: {$winnerUserToken} for media: {$this->media->id}");
        SendNotification::toExpoNotification($winnerUserToken, $title, $body, $data);
    }
}
