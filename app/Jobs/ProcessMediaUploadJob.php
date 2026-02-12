<?php

namespace App\Jobs;

use App\Jobs\ExpandLocationSelectionsJob;
use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Job to process media upload after file has been uploaded to storage.
 * 
 * This job updates the media record with file information, creates questions,
 * and dispatches ExpandLocationSelectionsJob if location selections exist.
 * 
 * Flow:
 * 1. Verifies file exists in storage
 * 2. Updates media record with file metadata and sets payment_status to 'paid'
 * 3. Creates quiz questions for the media
 * 4. Dispatches ExpandLocationSelectionsJob if location_selections exist
 * 5. Media model's updated event dispatches other dependent jobs (notifications, etc.)
 */
class ProcessMediaUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     *
     * @param string $mediaId The UUID of the media record
     * @param string $filePath The path to the uploaded file in storage
     * @param string $disk The storage disk name (e.g., 's3', 'public')
     * @param array $metadata Media metadata (name, mime_type, size, etc.)
     * @param array $questions Optional array of quiz questions
     */
    public function __construct(
        private readonly string $mediaId,
        private readonly string $filePath,
        private readonly string $disk,
        private readonly array $metadata,
        private readonly array $questions = []
    ) {}

    /**
     * Execute the job.
     * 
     * Processes the media upload by updating the media record, creating questions,
     * and dispatching location expansion if needed.
     *
     * @return void
     * @throws \Exception If file is not found or media processing fails
     */
    public function handle(): void
    {
        try {
            $media = Media::findOrFail($this->mediaId);
            
            // Check if media is already processed (has proper file info)
            if ($media->payment_status === 'paid' && $media->mime_type !== 'pending' && $media->path !== 'pending') {
                Log::info('Media already processed, skipping processing', [
                    'media_id' => $media->id,
                    'payment_status' => $media->payment_status,
                    'mime_type' => $media->mime_type,
                    'path' => $media->path,
                    'active' => $media->active
                ]);
                return;
            }

            if (!Storage::disk($this->disk)->exists($this->filePath)) {
                throw new \Exception("File not found in storage: {$this->filePath} on disk {$this->disk}");
            }
            
            $targetViews = (int) config('services.media.target_views', 10);
            
            $media->update([
                'name' => $this->metadata['name'],
                'file_name' => $this->metadata['file_name'],
                'mime_type' => $this->metadata['mime_type'],
                'size' => $this->metadata['size'],
                'path' => $this->filePath,
                'disk' => $this->disk,
                'description' => $this->metadata['description'] ?? null,
                'reward' => $this->metadata['reward'],
                'rounds' => $this->metadata['rounds'],
                'payment_status' => 'paid',
                'paid_at' => now(),
                'amount_paid' => $this->metadata['amount_paid'],
                'quiz_number' => rand(1, $targetViews),
                'active' => true,
            ]);

            $mediaService = app(MediaService::class);
            $mediaService->createQuestions($media, $this->questions);

            // Refresh media to get latest data including location_selections
            $media->refresh();

            // Dispatch ExpandLocationSelectionsJob if location selections exist
            // This ensures location expansion happens after media is successfully processed
            $locationSelections = $media->location_selections ?? [];
            if (!empty($locationSelections)) {
                ExpandLocationSelectionsJob::dispatch(
                    $media->id,
                    $locationSelections
                )->afterCommit();

                Log::info('ExpandLocationSelectionsJob dispatched after media processing', [
                    'media_id' => $media->id,
                    'location_selections_count' => count($locationSelections),
                ]);
            }

            Log::info('Media upload processed successfully', [
                'media_id' => $media->id,
                'file_path' => $this->filePath,
                'disk' => $this->disk,
                'has_location_selections' => !empty($locationSelections),
            ]);

            // Note: Email and push notifications are dispatched via Media model's updated event
            // after the database transaction commits, ensuring upload completion
            // is not blocked by notification failures.

        } catch (\Exception $e) {
            Log::error('Failed to process media upload', [
                'media_id' => $this->mediaId,
                'file_path' => $this->filePath,
                'disk' => $this->disk,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ProcessMediaUploadJob failed permanently', [
            'media_id' => $this->mediaId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Optionally update media status to indicate failure
        try {
            $media = Media::find($this->mediaId);
            if ($media) {
                $media->update([
                    'payment_status' => 'failed',
                    'active' => false
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to update media status after job failure', [
                'media_id' => $this->mediaId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
