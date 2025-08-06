<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class StoreMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $requestData = [
            'has_file' => $this->hasFile('file'),
            'all_data' => $this->all(),
            'files' => $this->allFiles(),
            'content_type' => $this->header('Content-Type'),
            'method' => $this->method(),
            'headers' => $this->headers->all(),
            'raw_content' => $this->getContent(),
            'request_keys' => array_keys($this->all()),
        ];

        Log::info('Validating media upload request', $requestData);

        // Check if any file was uploaded
        if (!$this->hasFile('file') && empty($this->allFiles())) {
            Log::error('No file found in request', $requestData);
            throw new \InvalidArgumentException('No file was uploaded. Please ensure you are sending a file with the key "file".');
        }

        return [
            'file' => [
                'required',
                'file',
                'max:10485760', // 10GB max (10 * 1024 * 1024 * 1024)
                'mimes:jpeg,png,jpg,gif,mp4'
            ],
            'description' => 'nullable|string|max:1000',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:255',
            'reward' => 'nullable|integer|min:100|max:100000000000',
            'questions' => 'nullable|array',
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
            'file.required' => 'Please upload a file.',
            'file.max' => 'The file size must not exceed 10GB.',
            'file.mimes' => 'The file must be a valid media file (jpeg, png, jpg, gif, mp4, pdf, doc, docx, xls, xlsx, ppt, pptx, txt).',
            'description.max' => 'The description must not exceed 1000 characters.',
            'tags.*.max' => 'Each tag must not exceed 255 characters.',
            'reward.min' => 'The reward must be at least 100.',
            'reward.max' => 'The reward must not exceed 100,000,000,000.',
            'questions.*.question.required' => 'Each question must have a question.',
            'questions.*.question.max' => 'Each question must not exceed 1000 characters.',
            'questions.*.answer.required' => 'Each question must have an answer.',
            'questions.*.answer.in' => 'Each answer must be one of: A, B, C, D.',
            'questions.*.option_a.required' => 'Each option must have an option A.',
            'questions.*.option_a.max' => 'Each option A must not exceed 255 characters.',
            'questions.*.option_b.required' => 'Each option must have an option B.',
            'questions.*.option_b.max' => 'Each option B must not exceed 255 characters.',
            'questions.*.option_c.required' => 'Each option must have an option C.',
            'questions.*.option_c.max' => 'Each option C must not exceed 255 characters.',
            'questions.*.option_d.required' => 'Each option must have an option D.',
            'questions.*.option_d.max' => 'Each option D must not exceed 255 characters.',
        ];
    }
}
