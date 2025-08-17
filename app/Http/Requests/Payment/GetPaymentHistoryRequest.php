<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class GetPaymentHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => 'nullable|integer|min:1|max:1000',
            'per_page' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|string|in:pending,succeeded,failed,canceled,refunded',
            'start_date' => 'nullable|date|before_or_equal:end_date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ];
    }

    public function messages(): array
    {
        return [
            'page.integer' => 'Page must be a valid number.',
            'page.min' => 'Page must be at least 1.',
            'page.max' => 'Page cannot exceed 1000.',
            
            'per_page.integer' => 'Items per page must be a valid number.',
            'per_page.min' => 'Items per page must be at least 1.',
            'per_page.max' => 'Items per page cannot exceed 100.',
            
            'status.in' => 'Status must be one of: pending, succeeded, failed, canceled, refunded.',
            
            'start_date.date' => 'Start date must be a valid date.',
            'start_date.before_or_equal' => 'Start date must be before or equal to end date.',
            
            'end_date.date' => 'End date must be a valid date.',
            'end_date.after_or_equal' => 'End date must be after or equal to start date.',
        ];
    }
} 