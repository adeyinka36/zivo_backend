<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserMediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        try {
            $uploaderUsername = 'Unknown';
            if ($this->relationLoaded('user') && $this->user) {
                $uploaderUsername = $this->user->username ?? $this->user->name ?? 'Unknown';
            }
            
            return [
                'id' => $this->id,
                'name' => $this->name,
                'file_name' => $this->file_name,
                'mime_type' => $this->mime_type,
                'media_type' => $this->media_type ?? $this->getMediaType(),
                'reward' => $this->reward ?? 0,
                'url' => $this->getFileUrl() ?? '',
                'description' => $this->description ?? '',
                'created_at' => $this->created_at?->toISOString() ?? now()->toISOString(),
                'updated_at' => $this->updated_at?->toISOString() ?? now()->toISOString(),
                'has_watched' => $this->getHasWatchedAttribute(),
                'thumbnail' => $this->getThumbnailUrl(),
                'uploader_id' => $this->user_id,
                'uploader_username' => $uploaderUsername,
                'view_count' => $this->view_count ?? 0,
            ];
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error in UserMediaResource', [
                'media_id' => $this->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
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
        if (!$user) {
            return false;
        }
        
        return $this->watchedByUsers()->where('user_id', $user->id)->exists();
    }
} 