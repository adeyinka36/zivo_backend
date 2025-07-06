<?php

namespace App\Jobs;

use App\Http\Resources\MediaResource;
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
        Log::info("QuizInvitation job started for media ID====: {$this->media->id}");
        $reward = ($this->media->reward)/100;
        $peopleWhoWatched = $this->media->watchedByUsers();
        $userTokens  = $peopleWhoWatched->pluck('push_token')->toArray();
//        if (empty($userTokens)) {
//            Log::info("No player completed the watch for media ID: {$this->media->id}");
//            return;
//        }
//        $selectedPlayers = array_rand($userTokens, 2);

        //just for tests
        $testUserTokens = User::whereIn('email', ['adeyinka.giwa36@gmail.com', 'kymakurumure@hotmail.com',])
            ->whereNotNull('push_token')
            ->pluck('push_token')
            ->toArray();

        $title = 'Quiz Invitation';
        $body = "You have been invited to participate in a quiz worth \$$reward in AWS voucher.";
        $data = [
            'media' => new MediaResource($this->media),
            'type' => 'quiz_invitation',
            'targetScreen' => '/quiz/' . $this->media->id,
        ];
          SendNotification::toExpoNotification($testUserTokens, $title, $body, $data);
    }
}
