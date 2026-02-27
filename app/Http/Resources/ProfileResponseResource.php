<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResponseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = $this['media'] ?? collect([]);
        
        return [
            'user' => new ProfileResource($this['user']),
            'media' => $media->isEmpty() ? [] : UserMediaResource::collection($media),
        ];
    }
} 