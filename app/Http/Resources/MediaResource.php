<?php

namespace App\Http\Resources;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    private static $watchedMediaIds = null;
    private static $currentUser = null;

    public function toArray(Request $request): array
    {
        /** @var Media $this */
        $user = $request->user();
        
        if (self::$currentUser !== $user?->id) {
            self::$currentUser = $user?->id;
            self::$watchedMediaIds = $user ? 
                \App\Models\MediaUserWatched::where('user_id', $user->id)
                    ->pluck('media_id')
                    ->toArray() : [];
        }

        $hasWatched = $user && in_array($this->id, self::$watchedMediaIds);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'media_type' => $this->media_type,
            'rounds' => $this->rounds,
            'url' => $this->getFileUrl(),
            'description' => $this->description,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'has_watched' => $hasWatched,
            'thumbnail' => $this->getThumbnailUrl(),
            'uploader_id' => $this->creator_id,
            'uploader_username' => $this->whenLoaded('creator', fn() => $this->creator->business_name),
            'view_count' => $this->whenLoaded('view_count', fn() => $this->view_count),
        ];
    }

    public function with(Request $request): array
    {
        return [
            'success' => true,
        ];
    }


}
