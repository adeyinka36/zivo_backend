<?php

namespace App\Services;

use App\Jobs\SendCurrentMediaNotificationJob;
use App\Models\Media;
use App\Models\User;
use App\Models\UserAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for handling media assignment to users.
 * 
 * Provides methods to assign media to users based on location priority
 * and manage user assignments.
 */
class MediaAssignmentService
{
    /**
     * Assign media to users to reach the target count.
     *
     * @param Media $media
     * @return int Number of users assigned
     */
    public function assignMediaToUsers(Media $media): int
    {
        if (!$media->relationLoaded('locations')) {
            $media->load('locations');
        }

        $targetCount = (int) config('services.media.target_views', 10) + 1;
        $currentCount = $media->assignments()->count();
        $neededCount = max(0, $targetCount - $currentCount);

        if ($neededCount === 0) {
            Log::info('Media already has sufficient assignments', [
                'media_id' => $media->id,
                'current_count' => $currentCount,
                'target_count' => $targetCount,
            ]);
            return 0;
        }

        $assignedUserIds = $media->assignments()->pluck('user_id')->toArray();
        $creatorId = $media->user_id;

        $selectedUsers = $this->selectUsers($media, $neededCount, $assignedUserIds, $creatorId);

        if (empty($selectedUsers)) {
            Log::warning('No eligible users found for media assignment', [
                'media_id' => $media->id,
                'needed_count' => $neededCount,
            ]);
            return 0;
        }

        $this->createAssignments($media, $selectedUsers);

        Log::info('Media assigned to users successfully test', [
            'media_id' => $media->id,
            'assigned_count' => count($selectedUsers),
            'total_assignments' => $currentCount + count($selectedUsers),
        ]);

        return count($selectedUsers);
    }

    /**
     * Select users based on location priority using coordinate-based radius search.
     *
     * @param Media $media
     * @param int $neededCount
     * @param array $excludeUserIds
     * @param string $creatorId
     * @return array
     */
    private function selectUsers(Media $media, int $neededCount, array $excludeUserIds, string $creatorId): array
    {
        $selectedUsers = [];
        $excludeUserIds = array_unique(array_merge($excludeUserIds, [$creatorId]));

        if ($media->locations->isEmpty()) {
            return $this->getUsers($neededCount, $excludeUserIds);
        }

        $coordinates = $this->extractCoordinates($media->locations);
        
        if (empty($coordinates)) {
            return $this->selectUsersByLocationHierarchy($media, $neededCount, $excludeUserIds);
        }

        $radii = [5, 10, 20, 50, 100, 250, 500, 1000];

        foreach ($radii as $radiusKm) {
            if (count($selectedUsers) >= $neededCount) {
                break;
            }

            $remaining = $neededCount - count($selectedUsers);
            $radiusUsers = $this->getUsersWithinRadius($coordinates, $radiusKm, $remaining, $excludeUserIds);
            
            $selectedUsers = array_merge($selectedUsers, $radiusUsers);
            $excludeUserIds = array_unique(array_merge($excludeUserIds, array_column($radiusUsers, 'id')));
        }

        if (count($selectedUsers) < $neededCount) {
            $remaining = $neededCount - count($selectedUsers);
            $generalUsers = $this->getUsers($remaining, $excludeUserIds);
            $selectedUsers = array_merge($selectedUsers, $generalUsers);
        }

        return array_slice($selectedUsers, 0, $neededCount);
    }

    /**
     * Extract valid coordinates from locations.
     *
     * @param \Illuminate\Database\Eloquent\Collection $locations
     * @return array Array of [latitude, longitude] pairs
     */
    private function extractCoordinates($locations): array
    {
        $coordinates = [];

        foreach ($locations as $location) {
            $lat = (float) $location->latitude;
            $lng = (float) $location->longitude;

            if ($lat != 0.0 && $lng != 0.0 && abs($lat) <= 90 && abs($lng) <= 180) {
                $coordinates[] = ['latitude' => $lat, 'longitude' => $lng];
            }
        }

        return $coordinates;
    }

    /**
     * Get users within specified radius of any coordinate point.
     *
     * @param array $coordinates Array of ['latitude' => float, 'longitude' => float]
     * @param float $radiusKm Radius in kilometers
     * @param int $limit Maximum number of users to return
     * @param array $excludeUserIds User IDs to exclude
     * @return array
     */
    private function getUsersWithinRadius(array $coordinates, float $radiusKm, int $limit, array $excludeUserIds): array
    {
        if (empty($coordinates) || $limit <= 0) {
            return [];
        }

        $query = User::query()
            ->whereNotNull('email_verified_at')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('latitude', '!=', 0)
            ->where('longitude', '!=', 0)
            ->whereNotIn('id', $excludeUserIds);

        if (count($coordinates) === 1) {
            $coord = $coordinates[0];
            $lat = (float) $coord['latitude'];
            $lng = (float) $coord['longitude'];

            $query->whereRaw(
                "6371 * acos(
                    cos(radians(?)) * cos(radians(users.latitude)) *
                    cos(radians(users.longitude) - radians(?)) +
                    sin(radians(?)) * sin(radians(users.latitude))
                ) <= ?",
                [$lat, $lng, $lat, $radiusKm]
            );
        } else {
            $query->where(function ($q) use ($coordinates, $radiusKm) {
                foreach ($coordinates as $coord) {
                    $lat = (float) $coord['latitude'];
                    $lng = (float) $coord['longitude'];

                    $q->orWhereRaw(
                        "6371 * acos(
                            cos(radians(?)) * cos(radians(users.latitude)) *
                            cos(radians(users.longitude) - radians(?)) +
                            sin(radians(?)) * sin(radians(users.latitude))
                        ) <= ?",
                        [$lat, $lng, $lat, $radiusKm]
                    );
                }
            });
        }

        return $query
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Fallback method: Select users by location hierarchy (city, country, continent).
     *
     * @param Media $media
     * @param int $neededCount
     * @param array $excludeUserIds
     * @return array
     */
    private function selectUsersByLocationHierarchy(Media $media, int $neededCount, array $excludeUserIds): array
    {
        $selectedUsers = [];

        $cityLocationIds = $media->locations->pluck('id')->toArray();
        $countryLocationIds = $this->getCountryLocationIds($media->locations);
        $continentLocationIds = $this->getContinentLocationIds($media->locations);

        $cityUsers = $this->getUsersByLocationIds($cityLocationIds, $neededCount, $excludeUserIds);
        $selectedUsers = array_merge($selectedUsers, $cityUsers);
        $excludeUserIds = array_unique(array_merge($excludeUserIds, array_column($cityUsers, 'id')));

        if (count($selectedUsers) < $neededCount) {
            $remaining = $neededCount - count($selectedUsers);
            $countryUsers = $this->getUsersByLocationIds($countryLocationIds, $remaining, $excludeUserIds);
            $selectedUsers = array_merge($selectedUsers, $countryUsers);
            $excludeUserIds = array_unique(array_merge($excludeUserIds, array_column($countryUsers, 'id')));
        }

        if (count($selectedUsers) < $neededCount) {
            $remaining = $neededCount - count($selectedUsers);
            $continentUsers = $this->getUsersByLocationIds($continentLocationIds, $remaining, $excludeUserIds);
            $selectedUsers = array_merge($selectedUsers, $continentUsers);
            $excludeUserIds = array_unique(array_merge($excludeUserIds, array_column($continentUsers, 'id')));
        }

        if (count($selectedUsers) < $neededCount) {
            $remaining = $neededCount - count($selectedUsers);
            $generalUsers = $this->getUsers($remaining, $excludeUserIds);
            $selectedUsers = array_merge($selectedUsers, $generalUsers);
        }

        return array_slice($selectedUsers, 0, $neededCount);
    }

    /**
     * Get users by location IDs.
     *
     * @param array $locationIds
     * @param int $limit
     * @param array $excludeUserIds
     * @return array
     */
    private function getUsersByLocationIds(array $locationIds, int $limit, array $excludeUserIds): array
    {
        if (empty($locationIds)) {
            return [];
        }

        return User::whereNotNull('email_verified_at')
            ->whereIn('location_id', $locationIds)
            ->whereNotIn('id', $excludeUserIds)
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get verified users without location restriction.
     *
     * @param int $limit
     * @param array $excludeUserIds
     * @return array
     */
    private function getUsers(int $limit, array $excludeUserIds): array
    {
        return User::whereNotIn('id', $excludeUserIds)
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get location IDs for countries of the media's locations.
     *
     * @param \Illuminate\Database\Eloquent\Collection $locations
     * @return array
     */
    private function getCountryLocationIds($locations): array
    {
        $countriesCodes = $locations->pluck('country_code')->unique()->toArray();

        return DB::table('locations')
            ->whereIn('country_code', $countriesCodes)
            ->pluck('id')
            ->toArray();
    }

    /**
     * Get location IDs for continents of the media's locations.
     *
     * @param \Illuminate\Database\Eloquent\Collection $locations
     * @return array
     */
    private function getContinentLocationIds($locations): array
    {
        $continents = $locations->pluck('continent')->unique()->toArray();

        return DB::table('locations')
            ->whereIn('continent', $continents)
            ->pluck('id')
            ->toArray();
    }

    /**
     * Create user assignments for the selected users.
     *
     * @param Media $media
     * @param array $users
     * @return void
     */
    private function createAssignments(Media $media, array $users): void
    {
        $currentUserIds = [];

        DB::transaction(function () use ($media, $users, &$currentUserIds) {
            foreach ($users as $userData) {
                $userId = $userData['id'];

                $hasCurrentMedia = UserAssignment::where('user_id', $userId)
                    ->where('current', true)
                    ->exists();

                $isCurrent = !$hasCurrentMedia;

                UserAssignment::updateOrCreate(
                    [
                        'user_id' => $userId,
                        'media_id' => $media->id,
                    ],
                    [
                        'current' => $isCurrent,
                    ]
                );

                if ($isCurrent) {
                    $currentUserIds[] = $userId;
                }
            }
        });

        if (!empty($currentUserIds)) {
            $this->sendAssignmentNotifications($media, $currentUserIds);
        }
    }

    /**
     * Send push notifications to users who received current media assignments.
     *
     * @param Media $media
     * @param array $userIds
     * @return int Number of notifications dispatched
     */
    private function sendAssignmentNotifications(Media $media, array $userIds): int
    {
        $eligibleUsers = User::whereIn('id', $userIds)
            ->where('push_notifications', true)
            ->whereNotNull('push_token')
            ->get()
            ->unique('push_token')
            ->values();

        if ($eligibleUsers->isEmpty()) {
            Log::info('No eligible users for assignment notifications', [
                'media_id' => $media->id,
                'total_assigned' => count($userIds),
            ]);
            return 0;
        }

        $notificationsDispatched = 0;

        foreach ($eligibleUsers as $user) {
            SendCurrentMediaNotificationJob::dispatch(
                $user->id,
                $media->id,
                'assigned'
            );
            $notificationsDispatched++;
        }

        Log::info('Assignment notifications dispatched', [
            'media_id' => $media->id,
            'notifications_dispatched' => $notificationsDispatched,
            'total_current_assignments' => count($userIds),
            'unique_tokens' => $notificationsDispatched,
        ]);

        return $notificationsDispatched;
    }
}

