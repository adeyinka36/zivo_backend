<?php

namespace App\Http\Requests\Media;

use App\Models\Media;
use Illuminate\Foundation\Http\FormRequest;

class DeleteMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $media = Media::find($this->route('id'));
        
        if (!$media) {
            return false; // Media not found
        }
        
        $creator = $this->user();
        
        if (!$creator instanceof \App\Models\Creator) {
            return false; // Must be a creator
        }
        
        return $media->creator_id === $creator->id;
    }

    public function rules(): array
    {
        return [
            'force_delete' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'force_delete.boolean' => 'Force delete must be true or false.',
        ];
    }
} 