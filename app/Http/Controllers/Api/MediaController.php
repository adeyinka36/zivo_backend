<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\ListMediaRequest;
use App\Http\Requests\Media\StoreMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Models\MediaUserWatched;
use App\Models\Tag;
use App\Models\User;
use App\Models\Payment;
use App\Services\MediaService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));
        $page = (int) $request->input('page', 1);
        $media = $query->paginate($perPage, ['*'], 'page', $page);

        return MediaResource::collection($media);
    }

    /**
     * Create payment intent with metadata only (no file upload)
     */
    public function createPaymentIntent(Request $request)
    {
        $request->validate([
            'description' => 'nullable|string|max:1000',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:255',
            'reward' => 'required|integer|min:100|max:100000000000',
            'questions' => 'nullable|array',
            'questions.*.question' => 'required|string|max:1000',
            'questions.*.answer' => 'required|string|in:A,B,C,D',
            'questions.*.option_a' => 'required|string|max:255',
            'questions.*.option_b' => 'required|string|max:255',
            'questions.*.option_c' => 'required|string|max:255',
            'questions.*.option_d' => 'required|string|max:255',
        ]);

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
     * Upload media after successful payment
     */
    public function uploadAfterPayment(Request $request)
    {
        $request->validate([
            'payment_id' => 'required|string|exists:payments,id',
            'file' => 'required|file|max:10485760|mimes:jpeg,png,jpg,gif,mp4',
            'description' => 'nullable|string|max:1000',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:255',
            'reward' => 'required|integer|min:100|max:100000000000',
            'questions' => 'nullable|array',
            'questions.*.question' => 'required|string|max:1000',
            'questions.*.answer' => 'required|string|in:A,B,C,D',
            'questions.*.option_a' => 'required|string|max:255',
            'questions.*.option_b' => 'required|string|max:255',
            'questions.*.option_c' => 'required|string|max:255',
            'questions.*.option_d' => 'required|string|max:255',
        ]);

        return DB::transaction(function () use ($request) {
            // Find the payment and verify it's successful
            $payment = Payment::where('id', $request->input('payment_id'))
                ->where('status', Payment::STATUS_SUCCEEDED)
                ->firstOrFail();

            // Find the associated media
            $media = $payment->media;
            
            if (!$media) {
                return response()->json(['message' => 'Media not found'], 404);
            }

            // Verify ownership
            if ($media->user_id !== $request->user()->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

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

            // Update tags
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

            // Update questions
            $media->questions()->delete();
            if ($request->has('questions')) {
                foreach ($request->input('questions') as $questionData) {
                    $media->questions()->create($questionData);
                }
            }

            // Delete the temporary uploaded media
            $uploadedMedia->delete();

            return response()->json([
                'media' => new MediaResource($media->load('tags')),
                'message' => 'Media uploaded successfully after payment'
            ]);
        });
    }

    public function show(Request $request, $id)
    {
        $media = Media::with('tags')->findOrFail($id);
        if ($media->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return new MediaResource($media);
    }

    public function destroy(Request $request, $id)
    {
        $media = Media::findOrFail($id);
        if ($media->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $media->tags()->detach();
        $media->delete();
        return response()->json(['message' => 'Media deleted successfully']);
    }

    public function markAsWatched(Media $media, User $user, Request $request)
    {
        //check that the user has not already watched the  media
        if (!$media->watchedByUsers()->where('user_id', $user->id)->exists()) {
            MediaUserWatched::create([
                'user_id' => $user->id,
                'media_id' => $media->id,
            ]);
        }

        // Return the updated media with has_watched status
        return response()->json([
            'message' => 'Media marked as watched',
            'success' => true,
            'media' => new MediaResource($media->load('tags'))
        ], 200);
    }
}
