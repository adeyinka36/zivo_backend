<?php

namespace App\Jobs;

use App\Models\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AllocateReward implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(Media $media, string $winner_id)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //send email of reward
    }
}
