<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // User can only update their own profile
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                'min:2',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.string' => 'Name must be a string.',
            'name.max' => 'Name cannot exceed 255 characters.',
            'name.min' => 'Name must be at least 2 characters.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'username',
        ];
    }
} 