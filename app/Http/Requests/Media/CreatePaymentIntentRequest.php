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
            'offer' => 'required|string|max:255',
            'rounds' => 'required|integer|min:1|max:1000000',
            'questions' => 'nullable|array|max:20',
            'questions.*.question' => 'required|string|max:50',
            'questions.*.answer' => 'required|string|max:38|regex:/^[a-zA-Z0-9\s]+$/',
            'location_id' => 'nullable|integer|exists:locations,id',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after:from_date',
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
            'offer.required' => 'Offer is required.',
            'offer.max' => 'Offer cannot exceed 255 characters.',
            
            'rounds.required' => 'Number of rounds is required.',
            'rounds.integer' => 'Rounds must be a valid number.',
            'rounds.min' => 'Rounds must be at least 1.',
            'rounds.max' => 'Rounds cannot exceed 1,000,000.',
            
            'from_date.required' => 'Valid from date is required.',
            'from_date.date' => 'Valid from date must be a valid date.',
            'to_date.required' => 'Valid until date is required.',
            'to_date.date' => 'Valid until date must be a valid date.',
            'to_date.after' => 'Valid until date must be after valid from date.',
            
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
            // Validate that to_date is at least 1 day after from_date
            if ($this->filled('from_date') && $this->filled('to_date')) {
                $fromDate = \Carbon\Carbon::parse($this->input('from_date'));
                $toDate = \Carbon\Carbon::parse($this->input('to_date'));
                $minToDate = $fromDate->copy()->addDay();
                
                if ($toDate->lt($minToDate)) {
                    $validator->errors()->add('to_date', 'The valid until date must be at least 1 day after the valid from date.');
                }
            }
            
            // Radius-based targeting has been removed; only explicit locations are supported.
        });
    }
} 