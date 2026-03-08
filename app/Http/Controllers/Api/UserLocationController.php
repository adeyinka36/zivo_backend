<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateLocationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Updates the authenticated user's location using only latitude and longitude.
 * Assignment and other logic use these coordinates directly; no location_id is stored.
 */
class UserLocationController extends Controller
{
    /**
     * Update the authenticated user's coordinates.
     *
     * @param UpdateLocationRequest $request Validated request containing latitude and longitude.
     * @return JsonResponse Success message.
     */
    public function update(UpdateLocationRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $data = $request->validated();

            $user->latitude = (float) $data['latitude'];
            $user->longitude = (float) $data['longitude'];
            $user->save();

            return response()->json([
                'message' => 'Location updated successfully',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to update user location', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to update location',
            ], 500);
        }
    }
}
