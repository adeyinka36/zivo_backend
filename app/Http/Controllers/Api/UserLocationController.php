<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateLocationRequest;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controller for managing user location updates.
 * 
 * This controller handles location updates by finding or creating location records
 * based on proximity thresholds and only updating the user's location when the
 * region_name changes.
 */
class UserLocationController extends Controller
{
    /**
     * Location threshold in degrees for finding nearby existing locations.
     * 
     * Approximately 1.1km at the equator. Used to determine if a location
     * already exists within this proximity to avoid creating duplicate locations.
     */
    private const LOCATION_THRESHOLD = 0.01;

    /**
     * Update the authenticated user's location.
     * 
     * This method processes location update requests by:
     * 1. Finding or creating a Location record based on the provided coordinates
     * 2. Determining if the user's location should be updated (only if region_name changed)
     * 3. Updating the user's location_id if necessary
     * 
     * @param UpdateLocationRequest $request The validated request containing location data
     * @return JsonResponse JSON response with success message and location_id
     */
    public function update(UpdateLocationRequest $request): JsonResponse
    {
        try {
            // Get the authenticated user
            $user = $request->user();
            $data = $request->validated();

            $location = $this->findOrCreateLocation($data);

            if ($this->shouldUpdateLocation($user, $location)) {
                $user->location_id = $location->id;
                $user->latitude = $data['latitude'];
                $user->longitude = $data['longitude'];
                $user->save();
            } else {
                if ($user->latitude != $data['latitude'] || $user->longitude != $data['longitude']) {
                    $user->latitude = $data['latitude'];
                    $user->longitude = $data['longitude'];
                    $user->save();
                }
            }

            return response()->json([
                'message' => 'Location updated successfully',
                'location_id' => $location->id,
            ]);
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('Failed to update user location', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to update location',
            ], 500);
        }
    }

    /**
     * Find an existing location within the threshold or create a new one.
     * 
     * This method searches for locations within LOCATION_THRESHOLD degrees
     * (approximately 1.1km) of the provided coordinates. If a location exists
     * within this range, it returns that location to avoid duplicates.
     * Otherwise, it creates a new Location record.
     * 
     * The operation is wrapped in a database transaction to ensure data integrity
     * in case of concurrent requests.
     * 
     * @param array $data Validated location data containing:
     *                    - latitude: float
     *                    - longitude: float
     *                    - region_name: string
     *                    - country: string
     *                    - continent: string
     *                    - timezone: string
     * @return Location The existing or newly created Location model
     */
    private function findOrCreateLocation(array $data): Location
    {
        return DB::transaction(function () use ($data) {
            $existing = Location::where('region_name', $data['region_name'])
                ->where('country', $data['country'])
                ->first();

            if ($existing) {
                if (!$existing->country_code && isset($data['country_code'])) {
                    $existing->update(['country_code' => $data['country_code']]);
                }
                if ($existing->continent !== $data['continent']) {
                    $existing->update(['continent' => $data['continent']]);
                }
                if ($existing->latitude == 0 && $existing->longitude == 0) {
                    $existing->update([
                        'latitude' => $data['latitude'],
                        'longitude' => $data['longitude'],
                        'timezone' => $data['timezone'],
                    ]);
                }
                $existing->refresh();
                return $existing;
            }

            return Location::create([
                'region_name' => $data['region_name'],
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'country' => $data['country'],
                'country_code' => $data['country_code'] ?? null,
                'continent' => $data['continent'],
                'timezone' => $data['timezone'],
            ]);
        });
    }

    /**
     * Determine if the user's location should be updated.
     * 
     * This method implements logic to prevent unnecessary location updates:
     * - Always update if user has no current location
     * - Don't update if user is already assigned to this location
     * - Only update if the region_name has changed
     * 
     * This prevents excessive database updates when the user is in the same
     * region and reduces unnecessary location changes for minor movements.
     * 
     * @param User $user The user whose location is being checked
     * @param Location $location The location record found/created for the new coordinates
     * @return bool True if the user's location should be updated, false otherwise
     */
    private function shouldUpdateLocation(User $user, Location $location): bool
    {
        if (!$user->location_id) {
            return true;
        }

        if ($user->location_id === $location->id) {
            return false;
        }

        $currentLocation = $user->location;
        if (!$currentLocation) {
            return true;
        }

        return $currentLocation->region_name !== $location->region_name;
    }
}
