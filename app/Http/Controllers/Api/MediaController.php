<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\ListMediaRequest;
use App\Http\Requests\Media\StoreMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function __construct(
        private readonly MediaService $mediaService
    ) {}

    public function index(ListMediaRequest $request)
    {
        $query = Media::with('tags');

        if ($request->has('search')) {
            $query = $this->mediaService->searchByTag($request->input('search'));
        }

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));
        $page = (int) $request->input('page', 1);
        $media = $query->paginate($perPage, ['*'], 'page', $page);

        return MediaResource::collection($media);
    }

    public function store(StoreMediaRequest $request)
    { 
        $media = $this->mediaService->store($request);
        return new MediaResource($media->load('tags'));
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
} 