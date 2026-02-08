<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Scheduled job to clean up old temporary upload files.
 * 
 * Deletes temp files older than 24 hours to prevent disk space issues
 * while ensuring files needed for recent payments are preserved.
 */
class CleanupTempUploadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $tempUploadsPath = 'temp_uploads';
            $maxAgeHours = 24;
            $maxAgeSeconds = $maxAgeHours * 3600;
            $now = time();

            if (!Storage::disk('local')->exists($tempUploadsPath)) {
                Log::info('CleanupTempUploadsJob: temp_uploads directory does not exist');
                return;
            }

            $files = Storage::disk('local')->files($tempUploadsPath);
            $deletedCount = 0;
            $keptCount = 0;
            $errorCount = 0;

            foreach ($files as $file) {
                try {
                    $lastModified = Storage::disk('local')->lastModified($file);
                    $ageSeconds = $now - $lastModified;

                    if ($ageSeconds > $maxAgeSeconds) {
                        Storage::disk('local')->delete($file);
                        $deletedCount++;

                        Log::debug('Deleted old temp file', [
                            'file' => $file,
                            'age_hours' => round($ageSeconds / 3600, 2),
                        ]);
                    } else {
                        $keptCount++;
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    Log::warning('Failed to process temp file during cleanup', [
                        'file' => $file,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('CleanupTempUploadsJob completed', [
                'total_files' => count($files),
                'deleted_count' => $deletedCount,
                'kept_count' => $keptCount,
                'error_count' => $errorCount,
                'max_age_hours' => $maxAgeHours,
            ]);
        } catch (\Exception $e) {
            Log::error('CleanupTempUploadsJob failed', [
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
        Log::error('CleanupTempUploadsJob failed permanently', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
