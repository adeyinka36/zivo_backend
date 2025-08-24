<?php

namespace App\Http\Resources;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Media $this */
        $user = $request->user();
        $hasWatched = $this->watchedByUsers()->where('user_id', $user->id)->exists();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'media_type' => $this->getMediaType(),
            'reward' => $this->reward,
            'url' => $this->getFileUrl(),
            'description' => $this->description,
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'has_watched' => $hasWatched,
            'thumbnail' => $this->thumbnail,
            'uploader_id' => $this->user_id,
            'uploader_username' => $this->user->username,
            'view_count' => $this->watchedByUsers()->count(),
        ];
    }

    public function with(Request $request): array
    {
        return [
            'success' => true,
        ];
    }

    /**
     * Get the media type based on mime type
     */
    protected function getMediaType(): string
    {
        $mimeType = $this->mime_type;

        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mimeType, 'application/pdf')) {
            return 'document';
        }

        if (str_starts_with($mimeType, 'text/')) {
            return 'text';
        }

        return 'other';
    }

}
