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
            'request_keys' => array_keys($this->all())
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
                'max:20440', // 20MB max
                'mimes:jpeg,png,jpg,gif,mp4,pdf,doc,docx,xls,xlsx,ppt,pptx,txt'
            ],
            'metadata' => 'nullable|array',
            'metadata.description' => 'nullable|string',
            'metadata.tags' => 'nullable|array',
            'metadata.tags.*' => 'string',
            'collection' => 'nullable|string|max:255'
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please select a file to upload.',
            'file.file' => 'The uploaded file is invalid.',
            'file.max' => 'The file size must not exceed 20MB.',
            'file.mimes' => 'The file must be a valid image, video, document, or text file.',
        ];
    }
}
