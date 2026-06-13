<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class GoogleLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string', 'min:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_token.required' => 'Google ID token is required.',
            'id_token.min' => 'Google ID token is invalid.',
        ];
    }
}
