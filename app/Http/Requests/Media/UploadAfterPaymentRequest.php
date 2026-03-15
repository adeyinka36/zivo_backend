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
            'offer' => 'required|string|max:255',
            'tags' => 'nullable|array|max:10',
            'tags.*' => 'string|max:255|regex:/^[a-zA-Z0-9\s\-_#]+$/',
            'rounds' => 'required|integer|min:1|max:1000000',
            'questions' => 'nullable|array|max:20',
            'questions.*.question' => 'required|string|max:50',
            'questions.*.answer' => 'required|string|max:38|regex:/^[a-zA-Z0-9\s]+$/',
            'questions.*.options' => 'nullable|array|size:3',
            'questions.*.options.*' => 'required|string|max:38|regex:/^[a-zA-Z0-9\s]+$/',
            'location_id' => 'nullable|integer|exists:locations,id',
            'location_selections' => 'nullable|array',
            'location_selections.*.type' => 'required|string|in:city,country,continent',
            'location_selections.*.identifier' => 'required|string',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after:from_date',
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
            
            if ($this->filled('payment_id')) {
                $creator = $this->user();
                
                if (!$creator instanceof \App\Models\Creator) {
                    $validator->errors()->add('payment_id', 'Unauthorized. Creator authentication required.');
                    return;
                }
                
                $payment = \App\Models\Payment::where('id', $this->input('payment_id'))
                    ->where('creator_id', $creator->id)
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

                if ($payment->media->creator_id !== $creator->id) {
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

                $frontendRounds = (int) $this->input('rounds');

                if ($frontendRounds !== (int) $media->rounds) {
                    $validator->errors()->add('rounds', 'Rounds value does not match the payment record. Please refresh and try again.');
                }
            }

            $questions = $this->input('questions', []);
            foreach ($questions as $qIndex => $q) {
                if (!empty($q['options']) && is_array($q['options']) && count($q['options']) === 3) {
                    $answer = trim((string) ($q['answer'] ?? ''));
                    $optionValues = array_map(fn ($o) => trim((string) $o), $q['options']);
                    foreach ($optionValues as $oi => $opt) {
                        if (strcasecmp($opt, $answer) === 0) {
                            $validator->errors()->add(
                                "questions.{$qIndex}.options.{$oi}",
                                'Each option must be different from the correct answer (these are wrong choices).'
                            );
                        }
                    }
                }
            }

            $hasLocationId = $this->filled('location_id');
            $hasLocationSelections = $this->filled('location_selections');

            if ($hasLocationId && $hasLocationSelections) {
                $validator->errors()->add('location_id', 'You can only use one location option: location_id or location_selections.');
                $validator->errors()->add('location_selections', 'You can only use one location option: location_id or location_selections.');
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

            'tags.max' => 'You can add at most 10 tags.',
            'tags.*.max' => 'Each tag cannot exceed 255 characters.',
            'tags.*.regex' => 'Tags can only contain letters, numbers, spaces, hyphens, underscores, and hashtags.',

            'questions.max' => 'You can add at most 20 questions.',
            'questions.*.question.required' => 'Question text is required.',
            'questions.*.question.max' => 'Question text cannot exceed 50 characters.',
            'questions.*.answer.required' => 'Correct answer is required.',
            'questions.*.answer.max' => 'Answer cannot exceed 38 characters.',
            'questions.*.answer.regex' => 'Answer may only contain letters, numbers, and spaces.',
            'questions.*.options.size' => 'When providing options, exactly 3 are required.',
            'questions.*.options.*.required_with' => 'Each option is required when options are provided.',
            'questions.*.options.*.max' => 'Each option cannot exceed 38 characters.',
            'questions.*.options.*.regex' => 'Options may only contain letters, numbers, and spaces.',
        ];
    }
}
