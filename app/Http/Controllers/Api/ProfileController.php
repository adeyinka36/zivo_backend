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
        
        if (!$user instanceof \App\Models\User) {
            return response()->json(['error' => 'Unauthorized. User authentication required.'], 401);
        }
        
        $profileData = $this->profileService->getProfileWithMedia($user);
        
        return response()->json(new ProfileResponseResource($profileData));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user instanceof \App\Models\User) {
            return response()->json(['error' => 'Unauthorized. User authentication required.'], 401);
        }
        
        $updatedUser = $this->profileService->updateProfile($user, $request->validated());
        $updatedUser->load('location');
        
        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => new ProfileResource($updatedUser),
        ]);
    }

    public function getUserMedia(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user instanceof \App\Models\User) {
            return response()->json(['error' => 'Unauthorized. User authentication required.'], 401);
        }
        
        $page = (int) $request->input('page', 1);
        $perPage = 10;
        
        return response()->json([
            'data' => [],
            'pagination' => [
                'current_page' => $page,
                'last_page' => 1,
                'per_page' => $perPage,
                'total' => 0,
            ],
        ]);
    }

    public function updatePushToken(Request $request): JsonResponse
    {
        $request->validate([
            'push_token' => 'required|string|max:255',
        ]);

        $user = $request->user();
        
        if (!$user instanceof \App\Models\User) {
            return response()->json(['error' => 'Unauthorized. User authentication required.'], 401);
        }
        
        $user->update(['push_token' => $request->push_token]);

        return response()->json([
            'message' => 'Push token updated successfully',
        ]);
    }

} 