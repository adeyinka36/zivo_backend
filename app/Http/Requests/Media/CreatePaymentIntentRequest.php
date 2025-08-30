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
            'tags' => 'nullable|array|max:10',
            'tags.*' => 'string|max:255|regex:/^[a-zA-Z0-9\s\-_#]+$/',
            'reward' => 'required|integer|min:100|max:100000000000',
            'quiz_number' => 'required|integer|min:1|max:100',
            'questions' => 'nullable|array|max:20',
            'questions.*.question' => 'required|string|max:1000',
            'questions.*.answer' => 'required|string|in:A,B,C,D',
            'questions.*.option_a' => 'required|string|max:255',
            'questions.*.option_b' => 'required|string|max:255',
            'questions.*.option_c' => 'required|string|max:255',
            'questions.*.option_d' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'reward.required' => 'Reward amount is required.',
            'reward.min' => 'Reward must be at least $1.00 (100 cents).',
            'reward.max' => 'Reward amount is too large.',
            'reward.integer' => 'Reward must be a valid amount in cents.',
            
            'description.max' => 'Description cannot exceed 1000 characters.',
            
            'tags.max' => 'You can add at most 10 tags.',
            'tags.*.max' => 'Each tag cannot exceed 255 characters.',
            'tags.*.regex' => 'Tags can only contain letters, numbers, spaces, hyphens, underscores, and hashtags.',
            
            'questions.max' => 'You can add at most 20 questions.',
            'questions.*.question.required' => 'Question text is required.',
            'questions.*.question.max' => 'Question text cannot exceed 1000 characters.',
            'questions.*.answer.required' => 'Correct answer is required.',
            'questions.*.answer.in' => 'Answer must be A, B, C, or D.',
            'questions.*.option_a.required' => 'Option A is required.',
            'questions.*.option_a.max' => 'Option A cannot exceed 255 characters.',
            'questions.*.option_b.required' => 'Option B is required.',
            'questions.*.option_b.max' => 'Option B cannot exceed 255 characters.',
            'questions.*.option_c.required' => 'Option C is required.',
            'questions.*.option_c.max' => 'Option C cannot exceed 255 characters.',
            'questions.*.option_d.required' => 'Option D is required.',
            'questions.*.option_d.max' => 'Option D cannot exceed 255 characters.',

            'quiz_number.required' => 'Quiz number is required.',
            'quiz_number.integer' => 'Quiz number must be a valid number.',
            'quiz_number.min' => 'Quiz number must be at least 1.',
            'quiz_number.max' => 'Quiz number cannot exceed 100.',
        ];
    }
} 