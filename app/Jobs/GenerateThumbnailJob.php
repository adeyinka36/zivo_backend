<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\ThumbnailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 1;

    public function __construct(
        private readonly string $mediaId
    ) {}

    public function handle(ThumbnailService $thumbnailService): void
    {
        try {
            $media = Media::find($this->mediaId);
            if (!$media) {
                Log::warning('Media not found for thumbnail generation', [
                    'media_id' => $this->mediaId
                ]);
                return;
            }

            if (!str_starts_with($media->mime_type, 'video/')) {
                return;
            }

            $videoPath = $this->getVideoPath($media);
            if (!$videoPath) {
                Log::warning('Video file not found for thumbnail generation', [
                    'media_id' => $media->id,
                    'path' => $media->path
                ]);
                return;
            }

            $isTempFile = $media->disk === 's3';

            try {
                $thumbnailPath = $thumbnailService->generateThumbnailFromPath($videoPath, $media->path);

                if ($thumbnailPath) {
                    $media->update(['thumbnail' => $thumbnailPath]);
                    Log::info('Thumbnail generated successfully', [
                        'media_id' => $media->id,
                        'thumbnail_path' => $thumbnailPath
                    ]);
                } else {
                    Log::warning('Failed to generate thumbnail', [
                        'media_id' => $media->id
                    ]);
                }
            } finally {
                if ($isTempFile && $videoPath && file_exists($videoPath)) {
                    @unlink($videoPath);
                }
            }

        } catch (\Exception $e) {
            Log::error('Thumbnail generation job failed', [
                'media_id' => $this->mediaId,
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
        Log::error('GenerateThumbnailJob failed permanently', [
            'media_id' => $this->mediaId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Note: We don't update media status here as the media upload was successful
        // Thumbnail generation failure doesn't affect the core media functionality
    }

    private function getVideoPath(Media $media): ?string
    {
        if ($media->disk === 's3') {
            try {
                $tempPath = storage_path('app/temp_' . uniqid() . '.mp4');
                $stream = Storage::disk('s3')->readStream($media->path);
                $fileStream = fopen($tempPath, 'w');
                
                while (!feof($stream)) {
                    fwrite($fileStream, fread($stream, 8192));
                }
                
                fclose($stream);
                fclose($fileStream);
                
                return $tempPath;
            } catch (\Exception $e) {
                Log::error('Failed to download video from S3', [
                    'media_id' => $media->id,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        }

        if ($media->disk === 'public') {
            return Storage::disk('public')->path($media->path);
        }

        return null;
    }

}