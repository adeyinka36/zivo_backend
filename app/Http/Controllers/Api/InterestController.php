<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Interest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class InterestController extends Controller
{
    /**
     * Get all interests ordered by their display order.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $cacheKey = 'interests_list';
        
        $interests = Cache::remember($cacheKey, 604800, function () {
            return Interest::orderBy('order')
                ->get(['id', 'name', 'slug', 'icon', 'order']);
        });

        return response()->json($interests);
    }
}
