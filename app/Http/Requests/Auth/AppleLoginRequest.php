<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class AppleLoginRequest extends FormRequest
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
            'identity_token' => ['required', 'string', 'min:100'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'identity_token.required' => 'Apple identity token is required.',
            'identity_token.min' => 'Apple identity token is invalid.',
        ];
    }
}
