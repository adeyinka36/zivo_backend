<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $creatorName = 'Unknown';
        if ($this->relationLoaded('creator') && $this->creator) {
            $creatorName = $this->creator->business_name ?? $this->creator->name ?? 'Unknown';
        }
        
        return [
            'id' => $this->id,
            'name' => $this->name ?? '',
            'file_name' => $this->file_name ?? '',
            'mime_type' => $this->mime_type ?? '',
            'media_type' => $this->media_type ?? $this->getMediaType(),
            'url' => $this->getFileUrl() ?? '',
            'description' => $this->offer ?? '',
            'created_at' => $this->created_at?->toIso8601String() ?? now()->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String() ?? now()->toIso8601String(),
            'has_watched' => $this->getHasWatchedAttribute(),
            'thumbnail' => $this->getThumbnailUrl(),
            'creator_id' => $this->creator_id,
            'creator_name' => $creatorName,
            'view_count' => $this->view_count ?? 0,
        ];
    }

    private function getMediaType(): string
    {
        if (!$this->mime_type) {
            return 'unknown';
        }
        
        if (str_starts_with($this->mime_type, 'video/')) {
            return 'video';
        }
        
        if (str_starts_with($this->mime_type, 'image/')) {
            return 'image';
        }
        
        return 'unknown';
    }


    private function getHasWatchedAttribute(): bool
    {
        $user = request()->user();
        if (!$user || !($user instanceof \App\Models\User)) {
            return false;
        }
        
        return $this->watchedByUsers()->where('user_id', $user->id)->exists();
    }
} 