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
use App\Jobs\GenerateThumbnailJob;
use App\Jobs\ProcessMediaUploadJob;
use App\Jobs\SendPushNotificationJob;
use App\Services\MediaWatchService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Collection;
use App\Models\Media;
use App\Models\MediaUserWatched;
use App\Models\Question;
use App\Models\User;
use App\Models\Payment;
use App\Services\MediaService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Throwable;

class MediaController extends Controller
{
    public function __construct(
        private readonly MediaService $mediaService,
        private readonly PaymentService $paymentService
    ) {}

    public function index(ListMediaRequest $request)
    {
        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);
        $forceRefresh = $request->input('force_refresh', false);
        
        $cacheKey = "media_list_page_{$page}_per_{$perPage}";
        
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }
        
        $media = Cache::remember($cacheKey, 60, function () use ($perPage, $page) {
            $query = Media::with(['user'])
                ->orderBy('created_at', 'desc')
                ->where('size', '>', 0)
                ->where('campaign_completed', false)
                ->where('mime_type', '!=', 'pending');

            return $query->paginate($perPage, ['*'], 'page', $page);
        });

        return MediaListResource::collection($media);
    }

    /**
     * Create payment intent with metadata only (no file upload)
     */
    public function createPaymentIntent(CreatePaymentIntentRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $request->validated();
            $user = $request->user();

            $media = $this->mediaService->createDraftMedia($data, $user);

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
     * Upload media after payment is completed
     */
    public function uploadAfterPayment(UploadAfterPaymentRequest $request): JsonResponse
    {
        $payment = Payment::where('id', $request->input('payment_id'))
            ->where('status', Payment::STATUS_SUCCEEDED)
            ->firstOrFail();

        $user = $request->user();

        $media = $payment->media;
        if (!$media) {
            return response()->json(['error' => 'Media not found'], 404);
        }

        $file = $request->file('file');
        
        $disk = app()->environment('testing') ? 'public' : 's3';
        $path = $disk === 's3' ? 'zivo_media/' . $file->hashName() : 'media/' . $file->hashName();

        $file->storeAs(
            $disk === 's3' ? 'zivo_media' : 'media',
            $file->hashName(),
            ['disk' => $disk]
        );

        // Calculate amounts from Media record (server-side) - don't trust frontend
        $expectedAmounts = $this->paymentService->calculateExpectedPaymentAmount($media);
        $totalAmountCents = $expectedAmounts['total_amount_cents'];

        // Verify frontend amounts match Media record (for validation, but don't use them)
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

        // Use Media record values (server-side) for metadata
        $metadata = [
            'name' => $file->getClientOriginalName(),
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'description' => $request->input('description') ?? $media->description,
            'reward' => (int) $media->reward,
            'rounds' => (int) $media->rounds,
            'amount_paid' => $totalAmountCents / 100,
            'quiz_number' => (int) ($request->input('quiz_number') ?? $media->quiz_number ?? 1),
        ];

        $jobs = collect([
            new ProcessMediaUploadJob($media->id, $path, $disk, $metadata, $request->input('questions', []))
        ]);

        if (str_starts_with($metadata['mime_type'], 'video/')) {
            $jobs->push(new GenerateThumbnailJob($media->id));
        }

        $jobs->push(new SendPushNotificationJob($user->id, $media->id));

        Bus::chain($jobs)
            ->catch( function (Throwable $e) {
                Log::error($e);
            })->dispatch();

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
     * @param MarkAsWatchedRequest $request
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

        $userAnswer = strtolower(trim($data['answer']));
        $correctAnswer = strtolower(trim($question->answer));
        
        similar_text($userAnswer, $correctAnswer, $similarity);
        $isCorrect = $similarity >= 80;

        if ($isCorrect) {
            $media->update(['prize_won' => true]);
            AllocateReward::dispatch($media, $request->user());
        }

        $resultCorrectness = $isCorrect ? 'correct' : 'incorrect';

        Log::info("Quiz answered $resultCorrectness incorrectly", [
            'media_id' => $media->id,
            'user_id' => $request->user()->id,
            'user_answer' => $userAnswer,
            'correct_answer' => $correctAnswer,
            'similarity' => $similarity
        ]);

        return response()->json([
            'message' => 'Quiz result processed successfully',
            'is_correct' => $isCorrect,
            'similarity' => round($similarity, 2)
        ], 200);
    }
}
