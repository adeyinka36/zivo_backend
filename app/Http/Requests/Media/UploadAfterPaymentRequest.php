<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class UploadAfterPaymentRequest extends FormRequest
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
            'payment_id' => 'required|string|exists:payments,id',
            'file' => [
                'nullable',
                'file',
                'max:5368709120',
                'mimes:' . implode(',', $allFormats)
            ],
            'description' => 'nullable|string|max:1000',
            'tags' => 'nullable|array|max:10',
            'tags.*' => 'string|max:255|regex:/^[a-zA-Z0-9\s\-_#]+$/',
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

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->filled('payment_id')) {
                $payment = \App\Models\Payment::where('id', $this->input('payment_id'))
                    ->where('user_id', $this->user()->id)
                    ->first();

                if (!$payment) {
                    $validator->errors()->add('payment_id', 'Payment not found or you do not have permission to access it.');
                    return;
                }

                if ($payment->status !== \App\Models\Payment::STATUS_SUCCEEDED) {
                    $validator->errors()->add('payment_id', 'Payment must be completed before uploading media.');
                    return;
                }

                if (!$payment->media) {
                    $validator->errors()->add('payment_id', 'No media record associated with this payment.');
                    return;
                }

                if ($payment->media->user_id !== $this->user()->id) {
                    $validator->errors()->add('payment_id', 'You do not have permission to upload media for this payment.');
                    return;
                }

                $media = $payment->media;
                $hasTempFile = $media->temp_file_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($media->temp_file_path);
                $hasUploadedFile = $this->hasFile('file');

                if (!$hasTempFile && !$hasUploadedFile) {
                    $validator->errors()->add('file', 'File is required. Either upload file now or ensure file was uploaded before payment.');
                    return;
                }

                if ($hasTempFile && $hasUploadedFile) {
                    $validator->errors()->add('file', 'File was already uploaded before payment. Do not send file again.');
                    return;
                }

                $frontendReward = (int) $this->input('reward');
                $frontendRounds = (int) $this->input('rounds');

                if ($frontendReward !== (int) $media->reward) {
                    $validator->errors()->add('reward', 'Reward amount does not match the payment record. Please refresh and try again.');
                }

                if ($frontendRounds !== (int) $media->rounds) {
                    $validator->errors()->add('rounds', 'Rounds value does not match the payment record. Please refresh and try again.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'payment_id.required' => 'Payment ID is required.',
            'payment_id.exists' => 'Invalid payment ID provided.',

            'file.nullable' => 'File is optional if already uploaded before payment.',
            'file.file' => 'The uploaded file is invalid.',
            'file.max' => 'The file size must not exceed 5GB.',
            'file.mimes' => 'The file must be a valid image or video format. Supported formats include: JPEG, PNG, GIF, BMP, TIFF, WebP, MP4, AVI, MOV, WebM, MKV, and many others.',

            'reward.required' => 'Reward amount is required.',
            'reward.min' => 'Reward must be at least $1.00 (100 cents).',
            'reward.max' => 'Reward cannot exceed $1,000.00 (1,000,000 cents).',
            'reward.integer' => 'Reward must be a valid amount in cents.',

            'rounds.required' => 'Number of rounds is required.',
            'rounds.integer' => 'Rounds must be a valid number.',
            'rounds.min' => 'Rounds must be at least 1.',
            'rounds.max' => 'Rounds cannot exceed 1,000,000.',

            'description.max' => 'Description cannot exceed 1000 characters.',

            'tags.max' => 'You can add at most 10 tags.',
            'tags.*.max' => 'Each tag cannot exceed 255 characters.',
            'tags.*.regex' => 'Tags can only contain letters, numbers, spaces, hyphens, underscores, and hashtags.',

            'questions.max' => 'You can add at most 20 questions.',
            'questions.*.question.required' => 'Question text is required.',
            'questions.*.question.max' => 'Question text cannot exceed 1000 characters.',
            'questions.*.answer.required' => 'Correct answer is required.',
            'questions.*.answer.max' => 'Answer cannot exceed 19 characters.',
            'questions.*.answer.regex' => 'Answer may only contain letters and numbers without spaces.',
        ];
    }
}
