<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower($this->input('email'))]);
        }
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
        ];
    }
} 