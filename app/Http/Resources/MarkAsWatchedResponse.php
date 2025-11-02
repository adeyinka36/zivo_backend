<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarkAsWatchedResponse extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'message' => 'Media marked as watched',
            'success' => true,
            'media' => new MediaResource($this->resource['media']),
            'trigger_quiz' => $this->resource['trigger_quiz'],
            'email_verified' => $this->resource['email_verified'],
            'watch_recorded' => $this->resource['watch_recorded'],
            'quiz_data' => $this->when(
                $this->resource['trigger_quiz'] && isset($this->resource['quiz_data']),
                $this->resource['quiz_data']
            ),
        ];
    }
}
