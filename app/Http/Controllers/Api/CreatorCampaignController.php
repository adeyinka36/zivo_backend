<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CreatorCampaignResource;
use App\Models\CampaignCode;
use App\Models\Creator;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CreatorCampaignController extends Controller
{
    /**
     * List campaigns (media) for the authenticated creator.
     *
     * @param Request $request
     * @return AnonymousResourceCollection|\Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user instanceof Creator) {
            return response()->json([
                'error' => 'Unauthorized. Creator authentication required.',
            ], 401);
        }

        $media = Media::where('creator_id', $user->id)
            ->where('payment_status', 'paid')
            ->orderByDesc('created_at')
            ->get();

        return CreatorCampaignResource::collection($media);
    }

    /**
     * Mark or unmark a campaign code as claimed by the business.
     *
     * @param Request $request
     * @param string $codeId
     * @return JsonResponse
     */
    public function setCodeClaimed(Request $request, string $codeId): JsonResponse
    {
        $user = $request->user();

        if (!$user instanceof Creator) {
            return response()->json([
                'error' => 'Unauthorized. Creator authentication required.',
            ], 401);
        }

        $validated = $request->validate([
            'claimed' => ['required', 'boolean'],
        ]);

        $campaignCode = CampaignCode::query()
            ->where('id', $codeId)
            ->whereHas('media', function ($query) use ($user): void {
                $query->where('creator_id', $user->id);
            })
            ->with('user')
            ->first();

        if (!$campaignCode) {
            return response()->json([
                'error' => 'Campaign code not found.',
            ], 404);
        }

        if ($campaignCode->user_id === null && $validated['claimed']) {
            return response()->json([
                'error' => 'Only assigned codes can be marked as claimed.',
            ], 422);
        }

        $campaignCode->claimed_at = $validated['claimed'] ? now() : null;
        $campaignCode->save();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $campaignCode->id,
                'code' => $campaignCode->code,
                'assigned' => (bool) $campaignCode->user_id,
                'assigned_to' => $campaignCode->user?->email,
                'assigned_at' => $campaignCode->assigned_at,
                'claimed' => $campaignCode->claimed_at !== null,
                'claimed_at' => $campaignCode->claimed_at,
            ],
        ]);
    }
}

