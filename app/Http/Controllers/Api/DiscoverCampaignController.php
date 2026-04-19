<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DiscoverCampaignResource;
use App\Services\CampaignDiscoveryService;
use Illuminate\Http\JsonResponse;

class DiscoverCampaignController extends Controller
{
    /**
     * Up to 20 random campaigns (or all rows if fewer than 20); informational fields only (no media file URLs).
     */
    public function index(CampaignDiscoveryService $campaignDiscoveryService): JsonResponse
    {
        if (! config('zivo.show_campaigns_list', true)) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign discovery is disabled.',
            ], 404);
        }

        $campaigns = $campaignDiscoveryService->discover(20)
            ->filter(static fn ($campaign) => $campaign->media !== null)
            ->values();

        return response()->json([
            'success' => true,
            'data' => DiscoverCampaignResource::collection($campaigns)->resolve(),
        ]);
    }
}
