<?php

namespace App\Http\Resources;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Media $this */
        return [
            'id' => $this->id,
            'name' => $this->name,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'url' => $this->getFileUrl(),
            'metadata' => array_merge($this->metadata ?? [], [
                'tags' => $this->whenLoaded('tags', function () {
                    return $this->tags->pluck('name')->toArray();
                }, $this->metadata['tags'] ?? [])
            ]),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }

    public function with(Request $request): array
    {
        return [
            'success' => true,
        ];
    }

    /**
     * Get the public or temporary URL for the file.
     */
    protected function getFileUrl(): ?string
    {
        if ($this->disk === 's3' && $this->path) {
            // If the bucket is public, this will be a public URL. If private, it's a temporary URL.
            try {
                // Try to get a temporary URL (valid for 1 hour)
                return Storage::disk('s3')->temporaryUrl($this->path, now()->addHour());
            } catch (\Exception $e) {
                // Fallback to regular URL if temporaryUrl fails
                return Storage::disk('s3')->url($this->path);
            }
        }
        if ($this->disk === 'public' && $this->path) {
            return Storage::disk('public')->url($this->path);
        }
        return null;
    }
} 