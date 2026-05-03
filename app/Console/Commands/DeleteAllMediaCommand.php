<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes every media row (storage + DB cascades) and clears local temp_uploads/.
 * Extremely destructive — guarded by confirmation unless --force.
 */
class DeleteAllMediaCommand extends Command
{
    protected $signature = 'media:delete-all
                            {--force : Skip confirmation prompts (dangerous)}
                            {--dry-run : Show counts only; do not delete anything}';

    protected $description = 'Delete ALL media (S3/local files per row + cascaded campaigns, payments, etc.) and wipe temp_uploads/ on local disk';

    public function __construct(private readonly MediaService $mediaService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = Media::query()->count();

        $tempPath = 'temp_uploads';
        $tempFileCount = 0;
        if (Storage::disk('local')->exists($tempPath)) {
            $tempFileCount = count(Storage::disk('local')->allFiles($tempPath));
        }

        $this->line('');
        $this->warn('═══════════════════════════════════════════════════════════');
        $this->warn('  DELETE ALL MEDIA');
        $this->warn('═══════════════════════════════════════════════════════════');
        $this->line('');
        $this->line("Media rows to remove: <fg=yellow>{$count}</>");
        $this->line("Files under local <fg=yellow>{$tempPath}/</> (after media): <fg=yellow>{$tempFileCount}</>");
        $this->line('');
        $this->line('Each media row is deleted via MediaService (main file, thumbnail, temp_file_path, then DB).');
        $this->line('Foreign keys remove related: campaigns, payments, assignments, watches, questions,');
        $this->line('media_locations, campaign_codes, etc.');
        $this->line('');

        if ($this->option('dry-run')) {
            $this->info('Dry run: no changes were made.');

            return self::SUCCESS;
        }

        if ($count === 0 && $tempFileCount === 0) {
            $this->info('Nothing to delete.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            if (! $this->confirm('Delete ALL media rows and related database data?', false)) {
                $this->info('Cancelled. Nothing was deleted.');

                return self::SUCCESS;
            }

            $phrase = (string) $this->ask('Type the phrase below exactly (case-sensitive) to continue:');
            if ($phrase !== 'DELETE ALL MEDIA') {
                $this->error('Confirmation phrase did not match. Expected: DELETE ALL MEDIA');
                $this->info('Cancelled. Nothing was deleted.');

                return self::FAILURE;
            }
        }

        $deletedMedia = 0;
        $mediaErrors = 0;

        if ($count > 0) {
            $bar = $this->output->createProgressBar($count);
            $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — deleting media…');
            $bar->start();

            try {
                Media::query()->orderBy('id')->chunkById(100, function ($chunk) use (&$deletedMedia, &$mediaErrors, $bar) {
                    foreach ($chunk as $media) {
                        try {
                            $this->mediaService->delete($media);
                            $deletedMedia++;
                        } catch (Throwable $e) {
                            $mediaErrors++;
                            Log::error('media:delete-all failed for one media row', [
                                'media_id' => $media->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                        $bar->advance();
                    }
                });
            } finally {
                $bar->finish();
                $this->newLine(2);
            }
        } else {
            $this->info('No media rows in the database; only clearing temp_uploads/.');
            $this->newLine();
        }

        $deletedTempFiles = 0;
        $tempErrors = 0;

        if (Storage::disk('local')->exists($tempPath)) {
            foreach (Storage::disk('local')->allFiles($tempPath) as $file) {
                try {
                    Storage::disk('local')->delete($file);
                    $deletedTempFiles++;
                } catch (Throwable $e) {
                    $tempErrors++;
                    Log::warning('media:delete-all failed to delete temp file', [
                        'file' => $file,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('media:delete-all completed', [
            'deleted_media_rows' => $deletedMedia,
            'media_errors' => $mediaErrors,
            'deleted_temp_files' => $deletedTempFiles,
            'temp_errors' => $tempErrors,
        ]);

        $this->info("Media rows deleted: {$deletedMedia}");
        if ($mediaErrors > 0) {
            $this->warn("Media delete errors (see logs): {$mediaErrors}");
        }
        $this->info("Temp upload files deleted: {$deletedTempFiles}");
        if ($tempErrors > 0) {
            $this->warn("Temp file errors (see logs): {$tempErrors}");
        }

        if ($mediaErrors > 0 || $tempErrors > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
