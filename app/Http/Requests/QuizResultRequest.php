<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuizResultRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return  true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_corrrect' => 'required|boolean',
            'question_id' => 'required|exists:questions,id',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_correct.required' => 'The is_correct field is required.',
            'is_correct.boolean' => 'The is_correct field must be true or false.',
            'question_id.required' => 'The question_id field is required.',
            'question_id.exists' => 'The selected question_id does not exist.',
        ];
    }
}
