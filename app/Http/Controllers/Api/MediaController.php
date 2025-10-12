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
use App\Http\Resources\MediaResource;
use App\Http\Resources\MediaWithQuestionResource;
use App\Jobs\AllocateReward;
use App\Jobs\GenerateThumbnailJob;
use App\Models\Media;
use App\Models\MediaUserWatched;
use App\Models\Question;
use App\Models\Tag;
use App\Models\User;
use App\Models\Payment;
use App\Services\MediaService;
use App\Services\PaymentService;
use App\Services\TagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MediaController extends Controller
{
    public function __construct(
        private readonly MediaService $mediaService
    ) {}

    public function index(ListMediaRequest $request)
    {
        $query = Media::with('tags')
            ->orderBy('created_at', 'desc')
            ->where('size', '>', 0)
            ->where('quiz_played', false);

        if ($request->filled('search')) {
            $query = $this->mediaService->searchByTag($request->input('search'));
        }

        $perPage = $request->input('per_page', 15);
        $page = $request->input('page', 1);
        $media = $query->paginate($perPage, ['*'], 'page', $page);

        return MediaResource::collection($media);
    }

    /**
     * Create payment intent with metadata only (no file upload)
     */
    public function createPaymentIntent(CreatePaymentIntentRequest $request)
    {
        return DB::transaction(function () use ($request) {
            // Create a temporary media record with metadata only
            $media = Media::create([
                'user_id' => $request->user()->id,
                'name' => 'Pending Upload',
                'file_name' => 'pending',
                'mime_type' => 'pending',
                'size' => 0,
                'path' => 'pending',
                'disk' => 'pending',
                'description' => $request->input('description'),
                'reward' => $request->input('reward'),
                'payment_status' => 'pending',
            ]);

            // Attach tags if provided
            if ($request->input('tags')) {
                $tagService = app(TagService::class);
                $tags = $tagService->createOrFindTags($request->input('tags', []));
                $media->tags()->attach($tags);
            }

            // Create questions if provided
            if ($request->has('questions')) {
                foreach ($request->input('questions') as $questionData) {
                    $media->questions()->create($questionData);
                }
            }

            // Create payment intent
            $paymentService = app(PaymentService::class);
            $paymentResult = $paymentService->createPaymentIntent($media, $request->user());

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
    public function uploadAfterPayment(UploadAfterPaymentRequest $request)
    {
        $payment = Payment::where('id', $request->input('payment_id'))
            ->where('status', Payment::STATUS_SUCCEEDED)
            ->firstOrFail();

        $media = $payment->media;

        $uploadedMedia = $this->mediaService->store(
            $request->file('file'),
            [
                'description' => $request->input('description'),
                'reward' => $request->input('reward'),
            ],
            $request->user()->id
        );

        DB::transaction(function () use ($media, $uploadedMedia, $request) {
            $media->update([
                'name' => $uploadedMedia->name,
                'file_name' => $uploadedMedia->file_name,
                'mime_type' => $uploadedMedia->mime_type,
                'size' => $uploadedMedia->size,
                'path' => $uploadedMedia->path,
                'disk' => $uploadedMedia->disk,
                'description' => $request->input('description'),
                'reward' => $request->input('reward'),
                'payment_status' => 'paid',
                'paid_at' => now(),
                'amount_paid' => $request->input('reward'),
                'quiz_number' => $request->input('quiz_number'),
            ]);

            $uploadedMedia->delete();
        });

        $this->processMediaMetadata($media, $request);

        // Dispatch thumbnail generation job asynchronously
        if ($media->media_type === 'video') {
            GenerateThumbnailJob::dispatch($media->id);
        }

        return response()->json([
            'media' => new MediaResource($media->load('tags')),
            'message' => 'Media uploaded successfully after payment'
        ]);
    }

    private function processMediaMetadata(Media $media, UploadAfterPaymentRequest $request): void
    {
        // Process tags efficiently
        if ($request->input('tags')) {
            $media->tags()->detach();
            $tagService = app(TagService::class);
            $tags = $tagService->createOrFindTags($request->input('tags', []));
            $media->tags()->attach($tags);
        }

        // Process questions efficiently
        if ($request->has('questions')) {
            $media->questions()->delete();
            $questionsData = $request->input('questions');
            if (!empty($questionsData)) {
                $media->questions()->createMany($questionsData);
            }
        }
    }

    public function show(ShowMediaRequest $request, $id)
    {
        $media = Media::with('tags')->findOrFail($id);
        return new MediaResource($media);
    }

    public function destroy(DeleteMediaRequest $request, $id)
    {
        $media = Media::findOrFail($id);

        // Use MediaService delete method which handles both S3 and database cleanup
        $this->mediaService->delete($media);

        return response()->json(['message' => 'Media deleted successfully']);
    }

    public function markAsWatched(Media $media, User $user, MarkAsWatchedRequest $request): JsonResponse
    {
        $shouldTriggerQuiz = false;
        $quizData = null;

        try {
            // Use firstOrCreate to handle duplicate key constraints gracefully
            $watchRecord = MediaUserWatched::firstOrCreate([
                'user_id' => $user->id,
                'media_id' => $media->id,
            ]);

            // Check if this was a new record (not already watched)
            $wasNewRecord = $watchRecord->wasRecentlyCreated;

            if ($wasNewRecord) {
                $watchCount = MediaUserWatched::where('media_id', $media->id)->count();

                if(($media->quiz_number === $watchCount) || true) {
                    $shouldTriggerQuiz = true;
                    $quizData = new MediaWithQuestionResource($media);
                }
            }

            $response = [
                'message' => 'Media marked as watched',
                'success' => true,
                'media' => new MediaResource($media->load('tags')),
                'trigger_quiz' => $shouldTriggerQuiz
            ];

            if ($shouldTriggerQuiz && $quizData) {
                $response['quiz_data'] = $quizData;
            }

            return response()->json($response, 200);

        } catch (\Exception $e) {
            Log::error('Error marking media as watched', [
                'media_id' => $media->id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to mark media as watched',
                'success' => false,
                'error' => 'Database error occurred'
            ], 500);
        }
    }

    public function processQuizResult(QuizResultRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Find the question by ID
        $media = $data['media_id'] ? Media::find($data['media_id']) : null;

        if (!$media) {
            return response()->json(['message' => 'Media not found'], 404);
        }

        if($data['is_correct']) {
//            AllocateReward::dispatch($media, $request->user());
            Log::info('reward won ----->', ['media_id' => $media->id, 'user_id' => $request->user()->id]);
        }else{
            MediaUserWatched::where('media_id', $media->id)->delete();
        }

        return response()->json([
            'message' => 'Quiz result processed successfully',
            'is_correct' => $data['is_correct'],
        ], 200);
    }
}
