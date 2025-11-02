<?php

namespace App\Http\Resources;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaListResource extends JsonResource
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
            'media_type' => $this->media_type,
            'reward' => $this->reward,
            'rounds' => $this->rounds,
            'url' => $this->getFileUrl(),
            'description' => $this->description,
            'created_at' => $this->created_at,
            'has_watched' => $hasWatched,
            'thumbnail' => $this->getThumbnailUrl(),
            'uploader_username' => $this->whenLoaded('user', fn() => $this->user->username),
            'view_count' => $this->view_count,
            'quiz_number' => $this->quiz_number,
        ];
    }

    public function with(Request $request): array
    {
        return [
            'success' => true,
        ];
    }
}