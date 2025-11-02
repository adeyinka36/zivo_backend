<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class StoreMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Comprehensive list of supported media formats
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
            'file' => [
                'required',
                'file',
                'max:20971520', // 20GB max (20 * 1024 * 1024 KB)
                'mimes:' . implode(',', $allFormats)
            ],
            'description' => 'nullable|string|max:1000',
            'reward' => 'nullable|integer|min:100|max:100000000000',
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
            'file.required' => 'Please select a media file to upload.',
            'file.file' => 'The uploaded file is invalid.',
            'file.max' => 'The file size must not exceed 20GB.',
            'file.mimes' => 'The file must be a valid image or video format. Supported formats include: JPEG, PNG, GIF, BMP, TIFF, WebP, MP4, AVI, MOV, WebM, MKV, and many others.',
            
            'description.max' => 'Description cannot exceed 1000 characters.',
            
            
            'reward.min' => 'Reward must be at least $1.00 (100 cents).',
            'reward.max' => 'Reward amount is too large.',
            'reward.integer' => 'Reward must be a valid amount in cents.',
            
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
        ];
    }
}
