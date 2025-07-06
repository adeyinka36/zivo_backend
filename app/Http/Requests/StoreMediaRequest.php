<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|max:10485760', // 10GB max
            'metadata' => 'nullable|array',
            'metadata.description' => 'nullable|string|max:1000',
            'metadata.tags' => 'nullable|array|max:10', // Limit number of tags
            'metadata.tags.*' => 'string|max:50|regex:/^[a-zA-Z0-9\s-]+$/' // Alphanumeric with spaces and hyphens
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please select a file to upload.',
            'file.max' => 'The file size must not exceed 10GB.',
            'metadata.tags.max' => 'You can add at most 10 tags.',
            'metadata.tags.*.max' => 'Each tag must not exceed 50 characters.',
            'metadata.tags.*.regex' => 'Tags can only contain letters, numbers, spaces, and hyphens.',
            'metadata.description.max' => 'Description must not exceed 1000 characters.'
        ];
    }
} 