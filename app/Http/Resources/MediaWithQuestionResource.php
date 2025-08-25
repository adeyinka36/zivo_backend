<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

class MediaWithQuestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'file_name' => $this->file_name,
            'reward' => $this->reward,
            'url' => $this->getFileUrl(),
            'description' => $this->description,
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'question' => $this->questions() ? new QuestionResource($this->questions()->inRandomOrder()->first()) : null,
            'mime_type' => $this->mime_type,
            'media_type' => $this->getMediaType(),
        ];
    }

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
