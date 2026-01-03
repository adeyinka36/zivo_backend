<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\MediaAssignmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to assign media to users based on location priority.
 * 
 * Assigns media to verified users in the following priority order:
 * 1. Users in cities specified by the media
 * 2. Users in countries specified by the media
 * 3. Users in continents specified by the media
 * 4. Any other verified users
 */
class AssignMediaToUsers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly string $mediaId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(MediaAssignmentService $assignmentService): void
    {
        $media = Media::with(['locations', 'user'])->find($this->mediaId);

        if (!$media) {
            Log::warning('Media not found for assignment', ['media_id' => $this->mediaId]);
            return;
        }

        $assignmentService->assignMediaToUsers($media);
    }
}
