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

//    //Todo: confirm that the method below can be removed
//    public function storeDraft(Request $request)
//    {
//        $request->validate([
//            'description' => 'nullable|string|max:1000',
//            'tags' => 'nullable|array',
//            'tags.*' => 'string|max:255',
//            'reward' => 'required|integer|min:100',
//            'questions' => 'nullable|array',
//            'questions.*.question' => 'required|string|max:500',
//            'questions.*.answer' => 'required|string|in:A,B,C,D',
//            'questions.*.option_a' => 'required|string|max:255',
//            'questions.*.option_b' => 'required|string|max:255',
//            'questions.*.option_c' => 'required|string|max:255',
//            'questions.*.option_d' => 'required|string|max:255',
//        ]);
//
//        return DB::transaction(function () use ($request) {
//            // Create draft media record without file
//            $media = Media::create([
//                'user_id' => $request->user()->id,
//                'name' => 'Draft Media',
//                'file_name' => null,
//                'mime_type' => null,
//                'size' => null,
//                'path' => null,
//                'disk' => null,
//                'description' => $request->input('description'),
//                'reward' => $request->input('reward'),
//                'payment_status' => 'pending',
//            ]);
//
//            // Attach tags
//            if ($request->has('tags')) {
//                $media->tags()->attach($request->input('tags'));
//            }
//
//            // Create questions
//            if ($request->has('questions')) {
//                foreach ($request->input('questions') as $questionData) {
//                    $media->questions()->create($questionData);
//                }
//            }
//
//            // Create payment intent
//            $paymentService = app(PaymentService::class);
//            $paymentResult = $paymentService->createPaymentIntent($media, $request->user());
//
//            return response()->json([
//                'draft' => new MediaResource($media->load('tags')),
//                'payment_intent' => [
//                    'client_secret' => $paymentResult['client_secret'],
//                    'payment_id' => $paymentResult['payment_id'],
//                ]
//            ]);
//        });
//    }

    public function store(StoreMediaRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $media = $this->mediaService->store(
                $request->file('file'),
                [
                    'description' => $request->input('description'),
                    'reward' => $request->input('reward', 100)
                ],
                $request->user()->id
            );

            if($request->input('tags')) {
                $tags = $request->input('tags', []);
                $mediaTags = [];
                foreach ($tags as $tagName) {
                    if (!Tag::where('name', $tagName)->exists()) {
                        $mediaTags[] = Tag::create([
                            'name' => $tagName,
                            'slug' => str($tagName)->slug()
                        ]);
                    }else{
                        $mediaTags[] = Tag::where('name', $tagName)->first();
                    }
                }
                $media->tags()->attach($mediaTags);
            }

            if ($request->has('questions')) {
                foreach ($request->input('questions') as $questionData) {
                    $media->questions()->create($questionData);
                }
            }

            // Create payment intent
            $paymentService = app(PaymentService::class);
            $paymentResult = $paymentService->createPaymentIntent($media, $request->user());

            return response()->json([
                'media' => new MediaResource($media->load('tags')),
                'payment_intent' => [
                    'client_secret' => $paymentResult['client_secret'],
                    'payment_id' => $paymentResult['payment_id'],
                ]
            ]);
        });
    }

//    //Todo: confirm that the method below can be removed
//    public function uploadAfterPayment(Request $request, $draftId)
//    {
//        $request->validate([
//            'file' => 'required|file|mimes:jpeg,jpg,png,gif,mp4,mov,avi|max:10485760', // 10GB max
//        ]);
//
//        $draft = Media::where('id', $draftId)
//            ->where('user_id', $request->user()->id)
//            ->where('payment_status', 'paid')
//            ->firstOrFail();
//
//        return DB::transaction(function () use ($request, $draft) {
//            // Upload the actual file
//            $media = $this->mediaService->uploadFile(
//                $request->file('file'),
//                $draft
//            );
//
//            return response()->json([
//                'media' => new MediaResource($media->load('tags')),
//                'message' => 'Media uploaded successfully'
//            ]);
//        });
//    }

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
