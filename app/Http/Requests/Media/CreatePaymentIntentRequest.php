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
        $imageFormats = [
            'jpeg', 'jpg', 'png', 'gif', 'bmp', 'tiff', 'tif', 'webp', 'svg', 'ico',
            'heic', 'heif', 'avif', 'jp2', 'j2k', 'jpf', 'jpm', 'jpg2', 'j2c',
            'jpc', 'jpx', 'mj2'
        ];

        $videoFormats = [
            'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv', 'm4v', 'mpg', 'mpeg',
            'mp2', 'mpe', 'mpv', 'm2v', '3gp', '3g2', 'f4v', 'f4p', 'f4a', 'f4b',
            'asf', 'rm', 'rmvb', 'vob', 'ogv', 'drc', 'mng', 'qt', 'yuv', 'mts',
            'm2ts', 'ts', 'viv', 'amv', 'roq', 'nsv', 'svi', 'mxf', 'divx', 'xvid'
        ];

        $allFormats = array_merge($imageFormats, $videoFormats);

        return [
            'description' => 'nullable|string|max:1000',
            'reward' => 'required|integer|min:100|max:1000000',
            'rounds' => 'required|integer|min:1|max:1000000',
            'questions' => 'nullable|array|max:20',
            'questions.*.question' => 'required|string|max:50',
            'questions.*.answer' => 'required|string|max:38|regex:/^[a-zA-Z0-9\s]+$/',
            'radius' => 'nullable|integer|min:1|max:1000',
            'location_id' => 'nullable|integer|exists:locations,id',
            'file' => [
                'nullable',
                'file',
                'max:5368709120',
                'mimes:' . implode(',', $allFormats)
            ],
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
            
            'radius.min' => 'Radius must be at least 1 km.',
            'radius.max' => 'Radius cannot exceed 1000 km.',
            
            'questions.max' => 'You can add at most 20 questions.',
            'questions.*.question.required' => 'Question text is required.',
            'questions.*.question.max' => 'Question text cannot exceed 50 characters.',
            'questions.*.answer.required' => 'Correct answer is required.',
            'questions.*.answer.max' => 'Answer cannot exceed 38 characters.',
            'questions.*.answer.regex' => 'Answer may only contain letters, numbers, and spaces.',
            
            'file.nullable' => 'File is optional.',
            'file.file' => 'The uploaded file is invalid.',
            'file.max' => 'The file size must not exceed 5GB.',
            'file.mimes' => 'The file must be a valid image or video format.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $hasRadius = $this->filled('radius');
            $hasLocationId = $this->filled('location_id');

            if ($hasRadius && $hasLocationId) {
                $validator->errors()->add('radius', 'You cannot use both radius and specific location. Please choose one option.');
                $validator->errors()->add('location_id', 'You cannot use both radius and specific location. Please choose one option.');
            }
        });
    }
} 