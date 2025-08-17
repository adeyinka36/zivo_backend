<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ClearMediaOlderThan24Hours extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:clear-old {--hours=24 : Hours after which to consider media old} {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Deleting media files older than specified hours from both database and storage (S3)";

    /**
     * The media service instance.
     */
    protected MediaService $mediaService;

    /**
     * Create a new command instance.
     */
    public function __construct(MediaService $mediaService)
    {
        parent::__construct();
        $this->mediaService = $mediaService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $isDryRun = $this->option('dry-run');
        $cutoffTime = Carbon::now()->subHours($hours);

        $this->info("Looking for media files older than {$hours} hours (before {$cutoffTime->format('Y-m-d H:i:s')})...");

        $oldMedia = Media::where('created_at', '<', $cutoffTime)
            ->with(['tags', 'questions', 'payments'])
            ->get();

        if ($oldMedia->isEmpty()) {
            $this->info('No media files found to delete.');
            return 0;
        }

        $this->info("Found {$oldMedia->count()} media file(s) to delete:");

        // Display what will be deleted
        $this->table(
            ['ID', 'Name', 'Size (bytes)', 'Storage Disk', 'Path', 'Created At'],
            $oldMedia->map(function ($media) {
                return [
                    $media->id,
                    $media->name,
                    number_format($media->size),
                    $media->disk,
                    $media->path,
                    $media->created_at->format('Y-m-d H:i:s'),
                ];
            })->toArray()
        );

        if ($isDryRun) {
            $this->warn('DRY RUN: No files were actually deleted.');
            $this->info('Run without --dry-run to perform the actual deletion.');
            return 0;
        }

        // Confirm before deletion
        if (!$this->confirm('Are you sure you want to delete these media files? This action cannot be undone.')) {
            $this->info('Operation cancelled.');
            return 0;
        }

        $deletedCount = 0;
        $errorCount = 0;
        $totalSize = 0;

        foreach ($oldMedia as $media) {
            try {
                DB::transaction(function () use ($media, &$deletedCount, &$totalSize) {
                    // Track file size for statistics
                    $totalSize += $media->size;

                    // Use MediaService delete method which handles both S3 and database cleanup
                    $this->mediaService->delete($media);

                    $deletedCount++;
                    $this->line("✓ Deleted: {$media->name} ({$media->id})");
                });
            } catch (\Exception $e) {
                $errorCount++;
                $this->error("✗ Failed to delete {$media->name}: " . $e->getMessage());

                // Log the error for debugging
                Log::error("Media cleanup failed for ID {$media->id}", [
                    'error' => $e->getMessage(),
                    'media' => $media->toArray()
                ]);
            }
        }

        // Display summary
        $this->newLine();
        $this->info("=== Cleanup Summary ===");
        $this->info("Successfully deleted: {$deletedCount} file(s)");
        $this->info("Errors encountered: {$errorCount}");
        $this->info("Total storage freed: " . $this->formatBytes($totalSize));

        if ($errorCount > 0) {
            $this->warn("Some files could not be deleted. Check the logs for details.");
            return 1;
        }

        $this->info("Media cleanup completed successfully!");
        return 0;
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
