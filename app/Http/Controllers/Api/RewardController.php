<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RewardResource;
use App\Models\CampaignCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof \App\Models\User) {
            return response()->json(['error' => 'Unauthorized. User authentication required.'], 401);
        }

        $rewards = CampaignCode::query()
            ->where('user_id', $user->id)
            ->with(['media.creator'])
            ->orderByDesc('assigned_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => RewardResource::collection($rewards)->resolve(),
        ]);
    }
}
