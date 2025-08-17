<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\ProfileResponseResource;
use App\Http\Resources\UserMediaResource;
use App\Services\ProfileService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    public function __construct(
        private ProfileService $profileService
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $profileData = $this->profileService->getProfileWithMedia($user);
        
        return response()->json(new ProfileResponseResource($profileData));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $updatedUser = $this->profileService->updateProfile($user, $request->validated());
        
        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => new ProfileResource($updatedUser),
        ]);
    }

    public function getUserMedia(Request $request): JsonResponse
    {
        $user = $request->user();
        $media = $this->profileService->getUserMedia($user);
        
        return response()->json([
            'data' => UserMediaResource::collection($media),
            'pagination' => [
                'current_page' => $media->currentPage(),
                'last_page' => $media->lastPage(),
                'per_page' => $media->perPage(),
                'total' => $media->total(),
            ],
        ]);
    }
} 