<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class UploadMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:10240', // 10MB max
                'mimes:jpeg,png,jpg,gif,mp4,pdf,doc,docx,xls,xlsx,ppt,pptx,txt'
            ],
            'metadata' => 'nullable|array',
            'metadata.*' => 'string'
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please select a file to upload.',
            'file.max' => 'The file size must not exceed 10MB.',
            'file.mimes' => 'The file must be a valid image, video, document, or text file.',
        ];
    }
}
