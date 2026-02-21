<?php

namespace App\Jobs;

use App\Jobs\AssignMediaToUsersJob;
use App\Models\Media;
use App\Models\Payment;
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
            $media = Media::with('payments')->find($this->mediaId);

            if (!$media) {
                Log::warning('Media not found for location expansion', [
                    'media_id' => $this->mediaId,
                ]);
                return;
            }

            $payment = Payment::where('media_id', $media->id)
                ->where('status', Payment::STATUS_SUCCEEDED)
                ->first();

            if (!$payment) {
                Log::info('Payment not succeeded yet, skipping location expansion', [
                    'media_id' => $this->mediaId,
                ]);
                return;
            }

            if (empty($this->locationSelections)) {
                Log::info('No location selections to expand, dispatching AssignMediaToUsersJob directly', [
                    'media_id' => $this->mediaId,
                ]);
                
                $media->refresh();
                
                if ($media->active && $media->payment_status === 'paid') {
                    AssignMediaToUsersJob::dispatch($media->id)->afterCommit();
                }
                
                return;
            }

            Log::info('Starting location expansion', [
                'media_id' => $this->mediaId,
                'selection_count' => count($this->locationSelections),
            ]);

            $locationIds = $locationService->expandLocationSelections($this->locationSelections);

            if (!empty($locationIds)) {
                $existingLocationIds = $media->locations()->pluck('locations.id')->toArray();
                $newLocationIds = array_values(array_diff($locationIds, $existingLocationIds));

                if (!empty($newLocationIds)) {
                    try {
                        $media->locations()->attach($newLocationIds);
                    } catch (\Exception $e) {
                        if (str_contains($e->getMessage(), 'duplicate key') || str_contains($e->getMessage(), 'Unique violation')) {
                            Log::warning('Some locations already exist, skipping duplicates', [
                                'media_id' => $this->mediaId,
                                'attempted_location_ids' => $newLocationIds,
                            ]);
                        } else {
                            throw $e;
                        }
                    }
                }

                Log::info('Location expansion completed', [
                    'media_id' => $this->mediaId,
                    'location_count' => count($locationIds),
                    'new_locations' => count($newLocationIds),
                    'existing_locations' => count($existingLocationIds),
                ]);
            } else {
                Log::warning('No locations found after expansion', [
                    'media_id' => $this->mediaId,
                ]);
            }

            $media->refresh();

            if ($media->active && $media->payment_status === 'paid') {
                AssignMediaToUsersJob::dispatch($media->id)->afterCommit();

                Log::info('AssignMediaToUsersJob dispatched after location expansion', [
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
