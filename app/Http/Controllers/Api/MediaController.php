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
use App\Jobs\ExpandLocationSelectionsJob;
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

            $media = $this->mediaService->createDraftMedia($data, $user);

            $file = $request->file('file');
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
        $tempFilePath = null;
        $metadata = [];

        if ($media->temp_file_path && Storage::disk('local')->exists($media->temp_file_path)) {
            $tempFilePath = $media->temp_file_path;
            
            $metadata = [
                'name' => $media->name,
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'description' => $request->input('description') ?? $media->description,
                'reward' => (int) $media->reward,
                'rounds' => (int) $media->rounds,
                'quiz_number' => rand(1, $targetViews),
            ];

            Log::info('Using pre-uploaded file for media processing', [
                'media_id' => $media->id,
                'temp_file_path' => $tempFilePath,
            ]);
        } else {
            $file = $request->file('file');
            
            if (!$file) {
                return response()->json(['error' => 'File is required'], 400);
            }

            $tempFileName = uniqid() . '_' . $file->hashName();
            $tempFilePath = 'temp_uploads/' . $tempFileName;
            $file->storeAs('temp_uploads', $tempFileName, ['disk' => 'local']);

            $metadata = [
                'name' => $file->getClientOriginalName(),
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'description' => $request->input('description') ?? $media->description,
                'reward' => (int) $media->reward,
                'rounds' => (int) $media->rounds,
                'quiz_number' => rand(1, $targetViews),
            ];
        }

        UploadFileToS3Job::dispatch(
            $media->id,
            $tempFilePath,
            $metadata,
            $request->input('questions', [])
        )->afterCommit();

        // Note: ExpandLocationSelectionsJob is now dispatched by ProcessMediaUploadJob
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

    public function processQuizResult(QuizResultRequest $request): JsonResponse
    {
        $data = $request->validated();
        $media = Media::find($data['media_id']);

        if (!$media) {
            return response()->json(['message' => 'Media not found'], 404);
        }
        $question = $media->questions()->first();
        if (!$question) {
            return response()->json(['message' => 'Question not found'], 404);
        }

        $userAnswer = strtoupper(trim($data['answer']));
        $correctAnswer = strtoupper(trim($question->answer));
        $isCorrect = $userAnswer === $correctAnswer;

        if ($isCorrect) {
            $media->update(['prize_won' => true]);
            AllocateReward::dispatch($media, $request->user());
        }

        $resultCorrectness = $isCorrect ? 'correct' : 'incorrect';

        Log::info("Quiz answered $resultCorrectness", [
            'media_id' => $media->id,
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
