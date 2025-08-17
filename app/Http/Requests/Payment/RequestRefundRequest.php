<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class RequestRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|min:10|max:500',
            'amount' => 'nullable|numeric|min:0.01',
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Please provide a reason for the refund request.',
            'reason.min' => 'Refund reason must be at least 10 characters.',
            'reason.max' => 'Refund reason cannot exceed 500 characters.',
            
            'amount.numeric' => 'Refund amount must be a valid number.',
            'amount.min' => 'Refund amount must be at least $0.01.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $payment = $this->route('payment');
            
            if ($payment && $this->filled('amount')) {
                $requestedAmount = $this->input('amount');
                $maxRefundAmount = $payment->amount;
                
                if ($requestedAmount > $maxRefundAmount) {
                    $validator->errors()->add('amount', "Refund amount cannot exceed the original payment amount of $" . number_format($maxRefundAmount, 2));
                }
            }
        });
    }
} 