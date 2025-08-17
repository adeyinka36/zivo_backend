<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class MarkAsWatchedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'timestamp' => 'nullable|integer|min:0',
            'device_info' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'timestamp.integer' => 'Timestamp must be a valid number.',
            'timestamp.min' => 'Timestamp must be a positive number.',
            'device_info.max' => 'Device info cannot exceed 255 characters.',
        ];
    }
} 