<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $email = mb_strtolower((string) $this->input('email', ''));
                    if ($email !== '' && User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
                        return;
                    }

                    if (User::where('username', $value)->exists()) {
                        $fail('This username is already taken.');
                    }
                },
            ],
            'email' => [
                'required',
                'string',
                'email:rfc,dns',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (User::whereRaw('LOWER(email) = ?', [mb_strtolower($value)])->exists()) {
                        $fail('An account with this email already exists. Please log in instead.');
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
            'name.required' => 'Please enter your name.',
            'name.max' => 'Name cannot exceed 255 characters.',
            
            'username.required' => 'Please choose a username.',
            'username.unique' => 'This username is already taken.',
            'username.max' => 'Username cannot exceed 255 characters.',
            
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'An account with this email already exists. Please log in instead.',
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