<?php

namespace App\Http\Requests\Media;

use App\Models\Media;
use Illuminate\Foundation\Http\FormRequest;

class ShowMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $media = Media::find($this->route('id'));
        
        if (!$media) {
            return false; // Media not found
        }
        
        return $media->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'include_questions' => 'nullable|boolean',
            'include_user' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'include_questions.boolean' => 'Include questions must be true or false.',
            'include_user.boolean' => 'Include user must be true or false.',
        ];
    }
} 