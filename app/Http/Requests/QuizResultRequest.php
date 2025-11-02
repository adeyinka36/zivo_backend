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
            'answer' => 'required|string|max:19|regex:/^[a-zA-Z0-9]+$/',
            'media_id' => 'required|exists:media,id',
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
            'answer.required' => 'The answer field is required.',
            'answer.string' => 'The answer must be a string.',
            'answer.max' => 'The answer may not be greater than 19 characters.',
            'answer.regex' => 'The answer may only contain letters and numbers without spaces.',
            'media_id.required' => 'The media_id field is required.',
            'media_id.exists' => 'The selected media_id is invalid.',
        ];
    }
}
