<?php

namespace App\Jobs;

use App\Jobs\AssignMediaToUsersJob;
use App\Jobs\GenerateCampaignCodesJob;
use App\Models\Campaign;
use App\Models\Media;
use App\Models\Location;
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
 * and attaches location IDs directly to the media.
 * 
 * Flow:
 * 1. Verifies file exists in storage
 * 2. Updates media record with file metadata and sets payment_status to 'paid'
 * 3. Creates quiz questions for the media
 * 4. Attaches location IDs directly if provided
 * 5. Dispatches AssignMediaToUsersJob
 * 6. Media model's updated event dispatches other dependent jobs (notifications, etc.)
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
            
            $now = now()->utc();
            $fromDate = $this->metadata['from_date'] ? \Carbon\Carbon::parse($this->metadata['from_date'])->utc() : null;
            $toDate = $this->metadata['to_date'] ? \Carbon\Carbon::parse($this->metadata['to_date'])->utc() : null;
            
            $isWithinDateRange = $fromDate && $toDate && $now->gte($fromDate) && $now->lte($toDate);
            
            $media->update([
                'name' => $this->metadata['name'],
                'file_name' => $this->metadata['file_name'],
                'mime_type' => $this->metadata['mime_type'],
                'size' => $this->metadata['size'],
                'path' => $this->filePath,
                'disk' => $this->disk,
                'description' => $this->metadata['description'] ?? null,
                'rounds' => $this->metadata['rounds'],
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'payment_status' => 'paid',
                'paid_at' => now(),
                'amount_paid' => $this->metadata['amount_paid'],
                'active' => $isWithinDateRange,
            ]);

            $mediaService = app(MediaService::class);
            $mediaService->createQuestions($media, $this->questions);

            if (isset($this->metadata['location_id']) && !empty($this->metadata['location_id'])) {
                $existingLocationIds = $media->locations()->pluck('locations.id')->toArray();

                if (!in_array($this->metadata['location_id'], $existingLocationIds)) {
                    try {
                        $media->locations()->attach($this->metadata['location_id']);
                    } catch (\Exception $e) {
                        if (str_contains($e->getMessage(), 'duplicate key') || str_contains($e->getMessage(), 'Unique violation')) {
                            Log::warning('Location already exists, skipping duplicate', [
                                'media_id' => $media->id,
                                'location_id' => $this->metadata['location_id'],
                            ]);
                        } else {
                            throw $e;
                        }
                    }
                }

                Log::info('Location ID attached to media', [
                    'media_id' => $media->id,
                    'location_id' => $this->metadata['location_id'],
                ]);
            }

            $media->refresh();

            // Create campaign entry for this media
            Campaign::firstOrCreate(
                [
                    'media_id' => $media->id,
                ],
                [
                    'creator_id' => $media->creator_id,
                    'active' => $isWithinDateRange,
                ]
            );

            // Generate reward codes for this campaign (one per target view)
            GenerateCampaignCodesJob::dispatch($media->id);

            if ($isWithinDateRange) {
                Log::info('Media is within date range, will be assigned by scheduled job', [
                    'media_id' => $media->id,
                    'from_date' => $fromDate?->toIso8601String(),
                    'to_date' => $toDate?->toIso8601String(),
                    'has_location_id' => !empty($this->metadata['location_id']),
                ]);
            } else {
                Log::info('Media is outside date range, will be activated by scheduled job', [
                    'media_id' => $media->id,
                    'from_date' => $fromDate?->toIso8601String(),
                    'to_date' => $toDate?->toIso8601String(),
                    'current_time' => $now->toIso8601String(),
                ]);
            }

            Log::info('Media upload processed successfully', [
                'media_id' => $media->id,
                'file_path' => $this->filePath,
                'disk' => $this->disk,
                'has_location_id' => !empty($this->metadata['location_id']),
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

    /**
     * Get location ID from coordinates (locations must be added manually to database).
     *
     * @param float $latitude
     * @param float $longitude
     * @return int|null Location ID or null if not found
     */
    private function getOrCreateLocationFromCoordinates(float $latitude, float $longitude): ?int
    {
        $lat = (float) $latitude;
        $lng = (float) $longitude;

        if ($lat == 0.0 || $lng == 0.0 || abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        $location = Location::where('latitude', $lat)
            ->where('longitude', $lng)
            ->first();

        if ($location) {
            return $location->id;
        }

        Log::warning('Location not found in database', [
            'latitude' => $lat,
            'longitude' => $lng,
        ]);

        return null;
    }

}
