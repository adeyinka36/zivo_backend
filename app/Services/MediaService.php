<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use App\Http\Requests\Media\StoreMediaRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Models\Tag;

class MediaService
{
    public function __construct(
        private readonly TagService $tagService
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
        ]);

        if (isset($metadata['tags']) && is_array($metadata['tags'])) {
            foreach ($metadata['tags'] as $tagName) {
                $tag = Tag::firstOrCreate(
                    ['name' => $tagName],
                    ['slug' => Str::slug($tagName)]
                );
                $media->tags()->attach($tag);
            }
        }

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

        $media->tags()->detach();
        return $media->delete();
    }

    public function searchByTag(string $searchTerm)
    {
        return Media::with('tags')->whereHas('tags', function ($query) use ($searchTerm) {
            $query->where('name', 'like', "%{$searchTerm}%")
                  ->orWhere('slug', 'like', "%{$searchTerm}%");
        });
    }
}
