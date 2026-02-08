<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\LocationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job to expand location selections and associate them with media.
 * 
 * This job runs asynchronously to avoid blocking the upload process
 * when processing large numbers of locations.
 */
class ExpandLocationSelectionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(
        private readonly string $mediaId,
        private readonly array $locationSelections
    ) {}

    /**
     * Execute the job.
     */
    public function handle(LocationService $locationService): void
    {
        try {
            $media = Media::find($this->mediaId);

            if (!$media) {
                Log::warning('Media not found for location expansion', [
                    'media_id' => $this->mediaId,
                ]);
                return;
            }

            if (empty($this->locationSelections)) {
                Log::info('No location selections to expand', [
                    'media_id' => $this->mediaId,
                ]);
                return;
            }

            Log::info('Starting location expansion', [
                'media_id' => $this->mediaId,
                'selection_count' => count($this->locationSelections),
            ]);

            $locationIds = $locationService->expandLocationSelections($this->locationSelections);

            if (!empty($locationIds)) {
                $media->locations()->sync($locationIds);

                Log::info('Location expansion completed', [
                    'media_id' => $this->mediaId,
                    'location_count' => count($locationIds),
                ]);
            } else {
                Log::warning('No locations found after expansion', [
                    'media_id' => $this->mediaId,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Location expansion failed', [
                'media_id' => $this->mediaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ExpandLocationSelectionsJob failed permanently', [
            'media_id' => $this->mediaId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
