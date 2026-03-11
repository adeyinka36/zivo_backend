<?php

namespace App\Http\Requests\Creator;

use App\Models\Creator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterCreatorWithVerificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
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
            'address' => ['required', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:20'],
            'is_limited_company' => ['required', 'boolean'],
            'crn' => ['nullable', 'string', 'max:8', 'regex:/^\d{8}$/'],
            'proof_of_address_type' => ['required', 'string', 'in:utility_bill,bank_statement,lease_agreement,hmrc_letter,business_rates,shopfront_selfie'],
            'proof_of_address' => ['required', 'file', 'mimes:jpeg,png,pdf,heic', 'max:5120'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower($this->input('email'))]);
        }
        // Convert string "1"/"0" or "true"/"false" to actual boolean for FormData
        if ($this->has('is_limited_company')) {
            $value = $this->input('is_limited_company');
            if (is_string($value)) {
                $this->merge([
                    'is_limited_company' => in_array(strtolower($value), ['1', 'true', 'on'], true),
                ]);
            }
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->sometimes('crn', 'required', function ($input) {
            return $input->is_limited_company === true;
        });
    }

    /**
     * Get custom messages for validator errors.
     */
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
            
            'address.required' => 'Please enter your trading address.',
            'address.max' => 'Address cannot exceed 500 characters.',
            
            'proof_of_address_type.required' => 'Please select a document type.',
            'proof_of_address_type.in' => 'Invalid document type selected.',
            'proof_of_address.required' => 'Please upload a proof of address document.',
            'proof_of_address.mimes' => 'Proof of address must be a JPEG, PNG, HEIC, or PDF.',
            'proof_of_address.max' => 'Proof of address document must not exceed 5MB.',
            'crn.regex' => 'Company Registration Number must be 8 digits.',
            'crn.required' => 'Company Registration Number is required for limited companies.',
        ];
    }
}
