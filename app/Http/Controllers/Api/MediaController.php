<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\ListMediaRequest;
use App\Http\Requests\Media\CreatePaymentIntentRequest;
use App\Http\Requests\Media\UploadAfterPaymentRequest;
use App\Http\Requests\Media\ShowMediaRequest;
use App\Http\Requests\Media\DeleteMediaRequest;
use App\Http\Requests\Media\MarkAsWatchedRequest;
use App\Http\Requests\QuizResultRequest;
use App\Http\Resources\MarkAsWatchedResponse;
use App\Http\Resources\MediaResource;
use App\Http\Resources\MediaListResource;
use App\Http\Resources\MediaWithQuestionResource;
use App\Jobs\AllocateReward;
use App\Jobs\ProcessMediaUploadJob;
use App\Jobs\UploadFileToS3Job;
use App\Services\MediaWatchService;
use Illuminate\Support\Collection;
use App\Models\Media;
use App\Models\MediaUserWatched;
use App\Models\Question;
use App\Models\User;
use App\Models\Payment;
use App\Services\MediaService;
use App\Services\PaymentService;
use App\Services\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MediaController extends Controller
{
    public function __construct(
        private readonly MediaService $mediaService,
        private readonly PaymentService $paymentService,
        private readonly LocationService $locationService
    ) {}

    public function index(ListMediaRequest $request)
    {
        $user = $request->user();

        if (!$user) {
            return MediaListResource::collection(collect([]));
        }

        $currentAssignment = \App\Models\UserAssignment::where('user_id', $user->id)
            ->where('current', true)
            ->with(['media.user'])
            ->first();

        if (!$currentAssignment || !$currentAssignment->media) {
            return MediaListResource::collection(collect([]));
        }

        $media = $currentAssignment->media;

        if ($media->size <= 0 || $media->campaign_completed || $media->mime_type === 'pending') {
            return MediaListResource::collection(collect([]));
        }

        return MediaListResource::collection(collect([$media]));
    }

    /**
     * Create payment intent with optional file upload.
     * 
     * If file is provided, uploads and verifies it before creating payment intent.
     * This ensures upload completes before payment is taken.
     */
    public function createPaymentIntent(CreatePaymentIntentRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $request->validated();
            $user = $request->user();
            $file = $request->file('file');

            $media = $this->mediaService->createDraftMedia($data, $user, $file);
            $tempFilePath = null;

            if ($file) {
                $targetViews = (int) config('services.media.target_views', 10);
                
                $tempFileName = uniqid() . '_' . $file->hashName();
                $tempFilePath = 'temp_uploads/' . $tempFileName;
                
                $stored = $file->storeAs('temp_uploads', $tempFileName, ['disk' => 'local']);
                
                if (!$stored || !Storage::disk('local')->exists($tempFilePath)) {
                    throw new \Exception('Failed to store file temporarily');
                }

                $media->update([
                    'temp_file_path' => $tempFilePath,
                    'name' => $file->getClientOriginalName(),
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ]);

                Log::info('File uploaded before payment intent creation', [
                    'media_id' => $media->id,
                    'temp_file_path' => $tempFilePath,
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ]);
            }

            $paymentResult = $this->paymentService->createPaymentIntent($media, $user);

            return response()->json([
                'payment_intent' => [
                    'client_secret' => $paymentResult['client_secret'],
                    'payment_id' => $paymentResult['payment_id'],
                    'existing' => $paymentResult['existing'] ?? false,
                ]
            ]);
        });
    }

    /**
     * Upload media after payment is completed.
     * 
     * Uses temp file if it was uploaded before payment, otherwise uses uploaded file.
     * Dispatches UploadFileToS3Job for asynchronous processing.
     * Returns immediately to provide quick response to frontend.
     */
    public function uploadAfterPayment(UploadAfterPaymentRequest $request): JsonResponse
    {
        $payment = Payment::where('id', $request->input('payment_id'))
            ->where('status', Payment::STATUS_SUCCEEDED)
            ->firstOrFail();

        $media = $payment->media;
        if (!$media) {
            return response()->json(['error' => 'Media not found'], 404);
        }

        $frontendRewardCents = (int) $request->input('reward');
        $frontendRounds = (int) $request->input('rounds');
        
        if ($frontendRewardCents !== (int) $media->reward || $frontendRounds !== (int) $media->rounds) {
            Log::warning('Frontend amounts mismatch Media record during upload', [
                'payment_id' => $payment->id,
                'media_id' => $media->id,
                'frontend_reward' => $frontendRewardCents,
                'media_reward' => $media->reward,
                'frontend_rounds' => $frontendRounds,
                'media_rounds' => $media->rounds,
            ]);
        }

        $targetViews = (int) config('services.media.target_views', 10);

        $fileInfo = $this->resolveFilePath($media, $request->file('file'));
        if (!$fileInfo['success']) {
            return response()->json(['error' => $fileInfo['error']], 400);
        }

        $metadata = $this->prepareUploadMetadata($media, $request, $targetViews);

        UploadFileToS3Job::dispatch(
            $media->id,
            $fileInfo['file_path'],
            $metadata,
            $request->input('questions', [])
        )->afterCommit();

        // Note: Location IDs are now attached directly in ProcessMediaUploadJob
        // after media processing succeeds, ensuring proper sequencing

        if ($media->temp_file_path) {
            $media->update(['temp_file_path' => null]);
        }

        return response()->json([
            'message' => 'Media upload initiated successfully'
        ]);
    }

    public function show(ShowMediaRequest $request, $id)
    {
        $media = Media::findOrFail($id);
        return new MediaResource($media);
    }

    public function destroy(DeleteMediaRequest $request, $id)
    {
        $media = Media::findOrFail($id);
        $this->mediaService->delete($media);

        return response()->json(['message' => 'Media deleted successfully']);
    }

    /**
     * Mark media as watched.
     *
     * @param Media $media
     * @param User $user
     * @param MediaWatchService $watchService
     * @return JsonResponse
     */
    public function markAsWatched(
        Media $media,
        User $user,
        MediaWatchService $watchService
    ): JsonResponse {
        try {
            $result = $watchService->markAsWatched($media, $user);

            $responseData = [
                'media' => $media,
                'trigger_quiz' => $result['trigger_quiz'],
                'email_verified' => $result['email_verified'],
                'watch_recorded' => $result['watch_recorded'],
                'quiz_data' => $result['quiz_data'],
            ];

            return response()->json(new MarkAsWatchedResponse($responseData), 200);
        } catch (\Exception $e) {
            Log::error('Error marking media as watched', [
                'media_id' => $media->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to mark media as watched',
                'success' => false,
                'error' => 'Database error occurred',
            ], 500);
        }
    }

    /**
     * Resolve file path for media upload.
     * 
     * Checks if media has a pre-uploaded temp file, otherwise handles new file upload.
     * 
     * @param Media $media
     * @param \Illuminate\Http\UploadedFile|null $uploadedFile
     * @return array{success: bool, file_path?: string, error?: string}
     */
    private function resolveFilePath(Media $media, ?\Illuminate\Http\UploadedFile $uploadedFile = null): array
    {
        if ($media->temp_file_path && Storage::disk('local')->exists($media->temp_file_path)) {
            Log::info('Using pre-uploaded file for media processing', [
                'media_id' => $media->id,
                'temp_file_path' => $media->temp_file_path,
            ]);

            return [
                'success' => true,
                'file_path' => $media->temp_file_path,
            ];
        }

        if (!$uploadedFile) {
            return [
                'success' => false,
                'error' => 'File is required',
            ];
        }

        $tempFileName = uniqid() . '_' . $uploadedFile->hashName();
        $tempFilePath = 'temp_uploads/' . $tempFileName;
        $uploadedFile->storeAs('temp_uploads', $tempFileName, ['disk' => 'local']);

        return [
            'success' => true,
            'file_path' => $tempFilePath,
        ];
    }

    /**
     * Prepare upload metadata from media and request data.
     * 
     * Consolidates metadata preparation logic for both temp file and new upload scenarios.
     * 
     * @param Media $media
     * @param UploadAfterPaymentRequest $request
     * @param int $targetViews
     * @return array
     */
    private function prepareUploadMetadata(Media $media, UploadAfterPaymentRequest $request, int $targetViews): array
    {
        $hasTempFile = $media->temp_file_path && Storage::disk('local')->exists($media->temp_file_path);
        $uploadedFile = $request->file('file');

        if ($hasTempFile) {
            return [
                'name' => $media->name,
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'description' => $request->input('description') ?? $media->description,
                'reward' => (int) $media->reward,
                'rounds' => (int) $media->rounds,
                'radius' => $request->has('radius') ? (int) $request->input('radius') : ($media->radius ?? null),
                'location_id' => $request->has('location_id') && !empty($request->input('location_id')) ? $request->input('location_id') : null,
                'quiz_number' => rand(1, $targetViews),
            ];
        }

        return [
            'name' => $uploadedFile->getClientOriginalName(),
            'file_name' => $uploadedFile->getClientOriginalName(),
            'mime_type' => $uploadedFile->getMimeType(),
            'size' => $uploadedFile->getSize(),
            'description' => $request->input('description') ?? $media->description,
            'reward' => (int) $media->reward,
            'rounds' => (int) $media->rounds,
            'radius' => $request->has('radius') ? (int) $request->input('radius') : ($media->radius ?? null),
            'location_id' => $request->has('location_id') && !empty($request->input('location_id')) ? $request->input('location_id') : null,
            'quiz_number' => rand(1, $targetViews),
        ];
    }

    public function processQuizResult(QuizResultRequest $request): JsonResponse
    {
        $data = $request->validated();
        $media = Media::find($data['media_id']);

        if (!$media) {
            return response()->json(['message' => 'Media not found'], 404);
        }

        // Find the specific question by ID to ensure we validate against the correct question
        // This is important when media has multiple questions - we need to validate against
        // the exact question that was shown to the user, not just the first one
        // Also verify the question belongs to this media to prevent validation against wrong question
        $question = $media->questions()->where('id', $data['question_id'])->first();
        if (!$question) {
            Log::warning('Question not found for media', [
                'media_id' => $media->id,
                'question_id' => $data['question_id'],
                'user_id' => $request->user()->id,
                'available_question_ids' => $media->questions()->pluck('id')->toArray(),
            ]);
            return response()->json(['message' => 'Question not found for this media'], 404);
        }

        // Normalize answers for comparison - remove all whitespace and convert to uppercase
        $userAnswer = strtoupper(preg_replace('/\s+/', '', trim($data['answer'])));
        $correctAnswer = strtoupper(preg_replace('/\s+/', '', trim($question->answer)));
        
        // Additional validation: ensure answers are single letters A-D
        if (!in_array($userAnswer, ['A', 'B', 'C', 'D'], true)) {
            Log::warning('Invalid answer format received', [
                'media_id' => $media->id,
                'question_id' => $question->id,
                'user_id' => $request->user()->id,
                'user_answer' => $data['answer'],
                'normalized_user_answer' => $userAnswer,
            ]);
            return response()->json(['message' => 'Invalid answer format'], 400);
        }
        
        if (!in_array($correctAnswer, ['A', 'B', 'C', 'D'], true)) {
            Log::error('Invalid correct answer stored in database', [
                'media_id' => $media->id,
                'question_id' => $question->id,
                'correct_answer_raw' => $question->answer,
                'normalized_correct_answer' => $correctAnswer,
            ]);
            return response()->json(['message' => 'Invalid question data'], 500);
        }
        
        $isCorrect = $userAnswer === $correctAnswer;

        if ($isCorrect) {
            $media->update(['prize_won' => true]);
            AllocateReward::dispatch($media, $request->user());
        }

        $resultCorrectness = $isCorrect ? 'correct' : 'incorrect';

        Log::info("Quiz answered $resultCorrectness", [
            'media_id' => $media->id,
            'question_id' => $question->id,
            'user_id' => $request->user()->id,
            'user_answer' => $userAnswer,
            'correct_answer' => $correctAnswer,
        ]);

        return response()->json([
            'message' => 'Quiz result processed successfully',
            'is_correct' => $isCorrect,
        ], 200);
    }
}
