<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CreatorCampaignResource;
use App\Models\Creator;
use App\Models\Media;
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
}

