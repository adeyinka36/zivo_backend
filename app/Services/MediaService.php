<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use App\Http\Requests\Media\StoreMediaRequest;
use App\Services\LocationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class MediaService
{
    public function __construct(
        private readonly ThumbnailService $thumbnailService,
        private readonly LocationService $locationService
    ) {}

    /**
     * Store a new media file.
     *
     * @param UploadedFile $file
     * @param array $metadata
     * @param int $userId
     * @return Media
     */
    public function store(UploadedFile $file, array $metadata, string $userId): Media
    {
        $disk = app()->environment('testing') ? 'public' : 's3';
        $path = $disk === 's3' ? 'zivo_media/' . $file->hashName() : 'media/' . $file->hashName();

        $file->storeAs(
            $disk === 's3' ? 'zivo_media' : 'media',
            $file->hashName(),
            ['disk' => $disk]
        );

        $media = Media::create([
            'user_id' => $userId,
            'name' => $file->getClientOriginalName(),
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
            'disk' => $disk,
            'description' => $metadata['description'] ?? null,
            'reward' => $metadata['reward'] ?? 100,
            'rounds' => $metadata['rounds'] ?? 1,
        ]);



        return $media;
    }

    /**
     * Upload file for an existing draft media record.
     *
     * @param UploadedFile $file
     * @param Media $draft
     * @return Media
     */
    public function uploadFile(UploadedFile $file, Media $draft): Media
    {
        $disk = app()->environment('testing') ? 'public' : 's3';
        $path = $disk === 's3' ? 'zivo_media/' . $file->hashName() : 'media/' . $file->hashName();

        $file->storeAs(
            $disk === 's3' ? 'zivo_media' : 'media',
            $file->hashName(),
            ['disk' => $disk]
        );

        // Update the draft with file information
        $draft->update([
            'name' => $file->getClientOriginalName(),
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
            'disk' => $disk,
        ]);

        return $draft;
    }

    /**
     * Delete a media file.
     *
     * @param Media $media
     * @return bool
     */
    public function delete(Media $media): bool
    {
        // Skip storage deletion for external URLs (disk = 'url')
        // These are external files not managed by our storage system
        if ($media->disk !== 'url') {
            try {
                Storage::disk($media->disk)->delete($media->path);
            } catch (\Exception $e) {
                Log::warning("Failed to delete file from storage", [
                    'media_id' => $media->id,
                    'disk' => $media->disk,
                    'path' => $media->path,
                    'error' => $e->getMessage()
                ]);
                // Continue with database deletion even if storage deletion fails
            }
        } else {
            Log::info("Skipping storage deletion for external URL", [
                'media_id' => $media->id,
                'path' => $media->path
            ]);
        }

        return $media->delete();
    }


    private function isVideoFile(UploadedFile $file): bool
    {
        $videoMimeTypes = [
            'video/mp4',
            'video/avi',
            'video/mov',
            'video/wmv',
            'video/flv',
            'video/webm',
            'video/mkv'
        ];
        
        return in_array($file->getMimeType(), $videoMimeTypes);
    }

    /**
     * Create a draft media record with metadata only
     */
    public function createDraftMedia(array $data, User $user): Media
    {
        $targetViews = (int) config('services.media.target_views', 10);
        
        $media = Media::create([
            'user_id' => $user->id,
            'name' => 'Pending Upload',
            'file_name' => 'pending',
            'mime_type' => 'pending',
            'size' => 0,
            'path' => 'pending',
            'disk' => 'pending',
            'description' => $data['description'] ?? null,
            'reward' => $data['reward'],
            'rounds' => $data['rounds'],
            'quiz_number' => $targetViews,
            'payment_status' => 'pending',
        ]);

        if (!empty($data['location_selections'])) {
            $locationIds = $this->locationService->expandLocationSelections($data['location_selections']);
            if (!empty($locationIds)) {
                $media->locations()->sync($locationIds);
            }
        }

        return $media;
    }


    /**
     * Create questions for media
     */
    public function createQuestions(Media $media, array $questions): void
    {
        if ($media->questions()->exists()) {
            return;
        }

        foreach ($questions as $questionData) {
            $media->questions()->create([
                'question' => $questionData['question'],
                'answer' => $questionData['answer'],
            ]);
        }

        if (empty($questions)) {
            $media->questions()->create([
                'question' => 'What is this platform?',
                'answer' => 'Zivo',
            ]);
        }
    }
}
