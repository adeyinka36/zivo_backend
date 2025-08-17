<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class ListMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1|max:1000',
            'sort' => 'nullable|string|in:created_at,name,reward,size',
            'order' => 'nullable|string|in:asc,desc',
        ];
    }

    public function messages(): array
    {
        return [
            'search.max' => 'Search term cannot exceed 255 characters.',
            'per_page.integer' => 'Items per page must be a valid number.',
            'per_page.min' => 'Items per page must be at least 1.',
            'per_page.max' => 'Items per page cannot exceed 100.',
            'page.integer' => 'Page must be a valid number.',
            'page.min' => 'Page must be at least 1.',
            'page.max' => 'Page cannot exceed 1000.',
            'sort.in' => 'Sort field must be one of: created_at, name, reward, size.',
            'order.in' => 'Order must be either asc or desc.',
        ];
    }
} 