<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => 'nullable|string|max:1000',
            'reward' => 'required|integer|min:100|max:1000000',
            'rounds' => 'required|integer|min:1|max:1000000',
            'questions' => 'nullable|array|max:20',
            'questions.*.question' => 'required|string|max:1000',
            'questions.*.answer' => 'required|string|max:19|regex:/^[a-zA-Z0-9]+$/',
            'location_selections' => 'nullable|array',
            'location_selections.*.type' => 'required|string|in:city,country,continent',
            'location_selections.*.identifier' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'reward.required' => 'Reward amount is required.',
            'reward.min' => 'Reward must be at least $1.00 (100 cents).',
            'reward.max' => 'Reward cannot exceed $1,000.00 (1,000,000 cents).',
            'reward.integer' => 'Reward must be a valid amount in cents.',
            
            'rounds.required' => 'Number of rounds is required.',
            'rounds.integer' => 'Rounds must be a valid number.',
            'rounds.min' => 'Rounds must be at least 1.',
            'rounds.max' => 'Rounds cannot exceed 1,000,000.',
            
            'description.max' => 'Description cannot exceed 1000 characters.',
            
            
            'questions.max' => 'You can add at most 20 questions.',
            'questions.*.question.required' => 'Question text is required.',
            'questions.*.question.max' => 'Question text cannot exceed 1000 characters.',
            'questions.*.answer.required' => 'Correct answer is required.',
            'questions.*.answer.max' => 'Answer cannot exceed 19 characters.',
            'questions.*.answer.regex' => 'Answer may only contain letters and numbers without spaces.',
        ];
    }
} 