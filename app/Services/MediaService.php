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

class MediaService
{
    public function __construct(
        private readonly TagService $tagService
    ) {}

    public function store(StoreMediaRequest $request): Media
    {
        Log::info('Store media request received', [
            'has_file' => $request->hasFile('file'),
            'all_data' => $request->all()
        ]);

        if (!$request->hasFile('file')) {
            throw new \InvalidArgumentException('No file was uploaded');
        }

        $file = $request->file('file');
        $metadata = $request->input('metadata', []);
        $fileName = $file->hashName();
        
        $media = Media::create([
            'user_id' => $request->user()->id,
            'name' => $file->getClientOriginalName(),
            'file_name' => $fileName,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => 'zivo_media/' . $fileName,
            'disk' => 's3',
            'metadata' => $metadata
        ]);

        // Store the file in S3
        Storage::disk('s3')->putFileAs('zivo_media', $file, $fileName);

        // Handle tags if present
        if (!empty($metadata['tags'])) {
            $tags = $this->tagService->createOrFindTags($metadata['tags']);
            $media->tags()->attach($tags->pluck('id'));
        }

        return $media;
    }

    public function delete(Media $media): void
    {
        // Delete the file from S3
        Storage::disk($media->disk)->delete($media->path);

        // Delete the media record
        $media->delete();
    }

    public function searchByTag(string $searchTerm)
    {
        return Media::with('tags')->whereHas('tags', function ($query) use ($searchTerm) {
            $query->where('name', 'like', "%{$searchTerm}%")
                  ->orWhere('slug', 'like', "%{$searchTerm}%");
        });
    }
} 