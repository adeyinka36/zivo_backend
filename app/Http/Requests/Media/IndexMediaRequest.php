<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class IndexMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'collection' => 'nullable|string|max:255',
            'mime_type' => 'nullable|string|max:255',
            'order_by' => 'nullable|string|in:created_at,updated_at,name,size',
            'order_direction' => 'nullable|string|in:asc,desc'
        ];
    }

    public function messages(): array
    {
        return [
            'per_page.min' => 'The per page value must be at least 1.',
            'per_page.max' => 'The per page value must not exceed 100.',
            'page.min' => 'The page number must be at least 1.',
            'order_by.in' => 'The order by field must be one of: created_at, updated_at, name, size.',
            'order_direction.in' => 'The order direction must be either asc or desc.'
        ];
    }
} 