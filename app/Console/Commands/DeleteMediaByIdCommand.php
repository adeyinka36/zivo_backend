<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Safely delete one media record by UUID: storage (main file, thumbnail, temp upload)
 * and the database row (foreign keys cascade to campaigns, payments, assignments, etc.).
 */
class DeleteMediaByIdCommand extends Command
{
    protected $signature = 'media:delete
                            {media_id : UUID of the media row to delete}
                            {--force : Skip confirmation (dangerous; use only in automation)}';

    protected $description = 'Delete a single media item by ID (S3/local files + DB; cascades campaign and related data)';

    public function __construct(private readonly MediaService $mediaService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $rawId = trim((string) $this->argument('media_id'));

        if ($rawId === '' || ! Str::isUuid($rawId)) {
            $this->error('Invalid media_id: must be a non-empty UUID.');

            return self::FAILURE;
        }

        /** @var Media|null $media */
        $media = Media::query()->find($rawId);

        if (! $media) {
            $this->error("No media found with id: {$rawId}");

            return self::FAILURE;
        }

        $this->line('');
        $this->warn('This will permanently delete:');
        $this->line('  • Main media file and thumbnail (if any) from storage');
        $this->line('  • Any temp upload file on the local disk (if any)');
        $this->line('  • The media database row and dependent rows (campaign, payments,');
        $this->line('    user assignments, watches, questions, location links, campaign codes, etc.)');
        $this->line('');

        $campaign = $media->campaign;
        $summary = [
            ['Field', 'Value'],
            ['ID', (string) $media->id],
            ['Name', $media->name ?? '—'],
            ['File', $media->file_name ?? '—'],
            ['Disk', (string) $media->disk],
            ['Path', (string) $media->path],
            ['Creator ID', (string) ($media->creator_id ?? '—')],
            ['Campaign row', $campaign ? (string) $campaign->id : 'none'],
            ['Payments', (string) $media->payments()->count()],
            ['Assignments', (string) $media->assignments()->count()],
            ['Watches', (string) $media->watches()->count()],
            ['Questions', (string) $media->questions()->count()],
            ['Location links', (string) $media->locations()->count()],
            ['Campaign codes', (string) $media->codes()->count()],
        ];

        $this->table($summary[0], array_slice($summary, 1));
        $this->line('');

        if (! $this->option('force')) {
            if (! $this->confirm('Are you sure you want to delete this media and all related data? This cannot be undone.', false)) {
                $this->info('Cancelled. Nothing was deleted.');

                return self::SUCCESS;
            }
        }

        try {
            $this->mediaService->delete($media);

            Log::info('media:delete completed', ['media_id' => $rawId]);
            $this->info("Media {$rawId} was deleted successfully.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('media:delete failed', [
                'media_id' => $rawId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error('Deletion failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
