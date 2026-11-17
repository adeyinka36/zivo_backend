<?php

namespace App\Http\Requests\Creator;

use App\Models\Creator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterCreatorWithVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
                    ->numbers(),
            ],
            'address' => ['required', 'string', 'max:500'],
            'postcode' => ['required', 'string', 'max:12', 'regex:/^[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}$/i'],
            'phone' => ['nullable', 'string', 'max:20'],
            'business_photo' => ['nullable', 'file', 'mimes:jpeg,jpg,png,heic,heif', 'max:5120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower($this->input('email'))]);
        }

        if ($this->filled('postcode')) {
            $postcode = strtoupper(trim((string) $this->input('postcode')));
            $postcode = preg_replace('/\s+/', ' ', $postcode) ?? $postcode;
            $this->merge(['postcode' => $postcode]);
        }
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

            'address.required' => 'Please enter your business address.',
            'address.max' => 'Address cannot exceed 500 characters.',

            'postcode.required' => 'Please enter your postcode.',
            'postcode.regex' => 'Please enter a valid UK postcode.',

            'business_photo.mimes' => 'Business photo must be a JPEG, PNG, or HEIC.',
            'business_photo.max' => 'Business photo must not exceed 5MB.',
        ];
    }
}
