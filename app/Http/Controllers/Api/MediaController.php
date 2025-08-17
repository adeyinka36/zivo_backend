<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\ListMediaRequest;
use App\Http\Requests\Media\CreatePaymentIntentRequest;
use App\Http\Requests\Media\UploadAfterPaymentRequest;
use App\Http\Requests\Media\ShowMediaRequest;
use App\Http\Requests\Media\DeleteMediaRequest;
use App\Http\Requests\Media\MarkAsWatchedRequest;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Models\MediaUserWatched;
use App\Models\Tag;
use App\Models\User;
use App\Models\Payment;
use App\Services\MediaService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;

class MediaController extends Controller
{
    public function __construct(
        private readonly MediaService $mediaService
    ) {}

    public function index(ListMediaRequest $request)
    {
        $query = Media::with('tags')->orderBy('created_at', 'desc')->where('size', '>', 0);

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
                $tags = $request->input('tags', []);
                $mediaTags = [];
                foreach ($tags as $tagName) {
                    if (!Tag::where('name', $tagName)->exists()) {
                        $mediaTags[] = Tag::create([
                            'name' => $tagName,
                            'slug' => str($tagName)->slug()
                        ]);
                    } else {
                        $mediaTags[] = Tag::where('name', $tagName)->first();
                    }
                }
                $media->tags()->attach($mediaTags);
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
        return DB::transaction(function () use ($request) {
            // Get payment and media (validation already done in request)
            $payment = Payment::where('id', $request->input('payment_id'))
                ->where('status', Payment::STATUS_SUCCEEDED)
                ->firstOrFail();

            $media = $payment->media;

            // Upload the actual file
            $uploadedMedia = $this->mediaService->store(
                $request->file('file'),
                [
                    'description' => $request->input('description'),
                    'reward' => $request->input('reward'),
                ],
                $request->user()->id
            );

            // Update the existing media record with the uploaded file data
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
            ]);

            $media->tags()->detach();
            if ($request->input('tags')) {
                $tags = $request->input('tags', []);
                $mediaTags = [];
                foreach ($tags as $tagName) {
                    if (!Tag::where('name', $tagName)->exists()) {
                        $mediaTags[] = Tag::create([
                            'name' => $tagName,
                            'slug' => str($tagName)->slug()
                        ]);
                    } else {
                        $mediaTags[] = Tag::where('name', $tagName)->first();
                    }
                }
                $media->tags()->attach($mediaTags);
            }

            $media->questions()->delete();
            if ($request->has('questions')) {
                foreach ($request->input('questions') as $questionData) {
                    $media->questions()->create($questionData);
                }
            }

            $uploadedMedia->delete();

            return response()->json([
                'media' => new MediaResource($media->load('tags')),
                'message' => 'Media uploaded successfully after payment'
            ]);
        });
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

    public function markAsWatched(Media $media, User $user, MarkAsWatchedRequest $request)
    {
        if (!$media->watchedByUsers()->where('user_id', $user->id)->exists()) {
            MediaUserWatched::create([
                'user_id' => $user->id,
                'media_id' => $media->id,
            ]);
        }

        return response()->json([
            'message' => 'Media marked as watched',
            'success' => true,
            'media' => new MediaResource($media->load('tags'))
        ], 200);
    }
}
