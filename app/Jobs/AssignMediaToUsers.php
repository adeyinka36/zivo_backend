<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Legacy job wrapper - redirects to unified AssignMediaToUsersJob.
 * 
 * @deprecated Use AssignMediaToUsersJob directly
 */
class AssignMediaToUsers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly string $mediaId
    ) {}

    public function handle(): void
    {
        AssignMediaToUsersJob::dispatch($this->mediaId)->afterCommit();
    }
}
