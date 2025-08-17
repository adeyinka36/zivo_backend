<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'media_type' => $this->getMediaType(),
            'reward' => $this->reward,
            'url' => $this->getFileUrl(),
            'description' => $this->description,
            'tags' => $this->tags->map(function ($tag) {
                return [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                ];
            }),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            'has_watched' => $this->getHasWatchedAttribute(),
            'thumbnail' => $this->getThumbnailUrl(),
            'uploader_id' => $this->user_id,
            'uploader_username' => $this->user->name,
        ];
    }

    private function getMediaType(): string
    {
        if (str_starts_with($this->mime_type, 'video/')) {
            return 'video';
        }
        
        if (str_starts_with($this->mime_type, 'image/')) {
            return 'image';
        }
        
        return 'unknown';
    }

    private function getThumbnailUrl(): ?string
    {
        // For now, return the main URL as thumbnail
        // In the future, this could be a separate thumbnail field
        return $this->getFileUrl();
    }

    private function getHasWatchedAttribute(): bool
    {
        $user = request()->user();
        if (!$user) {
            return false;
        }
        
        return $this->watchedByUsers()->where('user_id', $user->id)->exists();
    }
} 