<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResponseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user' => new ProfileResource($this['user']),
            'media' => UserMediaResource::collection($this['media']),
        ];
    }
} 