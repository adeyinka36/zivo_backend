<?php

namespace App\Http\Requests\Creator;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCreatorVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_limited_company' => ['required', 'boolean'],
            'crn' => ['nullable', 'string', 'max:8', 'regex:/^\d{8}$/'],
            'proof_of_address_type' => ['required', 'string', 'in:utility_bill,bank_statement,lease_agreement,hmrc_letter,business_rates,shopfront_selfie'],
            'proof_of_address' => ['required', 'file', 'mimes:jpeg,png,pdf,heic', 'max:5120'], // Max 5MB
        ];
    }

    public function messages(): array
    {
        return [
            'proof_of_address_type.required' => 'Please select a document type.',
            'proof_of_address_type.in' => 'Invalid document type selected.',
            'proof_of_address.required' => 'Please upload a proof of address document.',
            'proof_of_address.mimes' => 'Proof of address must be a JPEG, PNG, HEIC, or PDF.',
            'proof_of_address.max' => 'Proof of address document must not exceed 5MB.',
            'crn.regex' => 'Company Registration Number must be 8 digits.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->sometimes('crn', 'required', function ($input) {
            return $input->is_limited_company === true;
        });
    }
}
