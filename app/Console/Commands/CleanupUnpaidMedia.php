<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes media that have no successful payment and are older than 30 minutes.
 * Deletes any temp files stored on those media first, then deletes the media rows
 * (payments are cascade-deleted). Keeps temp files and DB in sync.
 */
class CleanupUnpaidMedia extends Command
{
    protected $signature = 'media:cleanup-unpaid
                            {--minutes=30 : Age in minutes after which unpaid media are deleted}';

    protected $description = 'Delete unpaid/uncompleted media older than 30 minutes and their temp files';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $cutoff = Carbon::now()->subMinutes($minutes);

        $this->info("Cleaning up media with no successful payment older than {$minutes} minutes (before {$cutoff})...");

        $query = Media::where('created_at', '<', $cutoff)
            ->where(function ($q) {
                $q->where('payment_status', '!=', 'paid')
                    ->orWhereNull('payment_status');
            });

        $media = $query->get();
        $deletedMedia = 0;
        $deletedTempFiles = 0;

        foreach ($media as $item) {
            if ($this->mediaHasSuccessfulPayment($item)) {
                continue;
            }

            DB::transaction(function () use ($item, &$deletedMedia, &$deletedTempFiles) {
                if ($item->temp_file_path) {
                    $path = $item->temp_file_path;
                    if (Storage::disk('local')->exists($path)) {
                        try {
                            Storage::disk('local')->delete($path);
                            $deletedTempFiles++;
                            Log::debug('CleanupUnpaidMedia: deleted temp file', [
                                'media_id' => $item->id,
                                'temp_file_path' => $path,
                            ]);
                        } catch (\Throwable $e) {
                            Log::warning('CleanupUnpaidMedia: failed to delete temp file', [
                                'media_id' => $item->id,
                                'path' => $path,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }

                $item->delete();
                $deletedMedia++;
            });
        }

        $this->info("Deleted {$deletedMedia} unpaid media and {$deletedTempFiles} temp file(s).");
        Log::info('CleanupUnpaidMedia completed', [
            'deleted_media' => $deletedMedia,
            'deleted_temp_files' => $deletedTempFiles,
            'cutoff' => $cutoff->toIso8601String(),
        ]);

        return 0;
    }

    private function mediaHasSuccessfulPayment(Media $media): bool
    {
        return $media->payments()
            ->where('status', Payment::STATUS_SUCCEEDED)
            ->exists();
    }
}
