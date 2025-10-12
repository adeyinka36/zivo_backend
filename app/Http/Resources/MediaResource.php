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
        $hasWatched = $user ? \App\Models\MediaUserWatched::where('media_id', $this->id)
            ->where('user_id', $user->id)
            ->exists() : false;


        return [
            'id' => $this->id,
            'name' => $this->name,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'media_type' => $this->media_type,
            'reward' => $this->reward,
            'url' => $this->getFileUrl(),
            'description' => $this->description,
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'has_watched' => $hasWatched,
            'thumbnail' => $this->getThumbnailUrl(),
            'uploader_id' => $this->user_id,
            'uploader_username' => $this->user->username,
            'view_count' => $this->watchedByUsers()->count(),
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
