<?php

namespace App\Http\Requests\Creator;

use Illuminate\Foundation\Http\FormRequest;

class LoginCreatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc,dns', 'indisposable'],
            'password' => ['required'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.indisposable' => 'Please enter a valid email address.',
            'email' => 'Please enter a valid email address.',
        ];
    }
}
