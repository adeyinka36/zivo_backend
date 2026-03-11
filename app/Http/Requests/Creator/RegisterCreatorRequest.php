<?php

namespace App\Http\Requests\Creator;

use App\Models\Creator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterCreatorRequest extends FormRequest
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
            'business_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email:rfc,dns',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (Creator::whereRaw('LOWER(email) = ?', [mb_strtolower($value)])->exists()) {
                        $fail('This email is already registered.');
                    }
                },
                'indisposable',
            ],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'business_name.required' => 'Please enter your business name.',
            'business_name.max' => 'Business name cannot exceed 255 characters.',
            
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'This email is already registered.',
            'email.max' => 'Email cannot exceed 255 characters.',
            'email.indisposable' => 'Please enter a valid email address.',
            
            'password.required' => 'Please enter a password.',
            'password.confirmed' => 'Password confirmation does not match.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.mixed' => 'Password must contain at least one uppercase and one lowercase letter.',
            'password.numbers' => 'Password must contain at least one number.',
        ];
    }
}
