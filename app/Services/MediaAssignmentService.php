<?php

namespace App\Services;

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

        Log::info('Media assigned to users successfully', [
            'media_id' => $media->id,
            'assigned_count' => count($selectedUsers),
            'total_assignments' => $currentCount + count($selectedUsers),
        ]);

        return count($selectedUsers);
    }

    /**
     * Select users based on location priority.
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
        $countries = $locations->pluck('country')->unique()->toArray();

        return DB::table('locations')
            ->whereIn('country', $countries)
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
        DB::transaction(function () use ($media, $users) {
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
            }
        });
    }
}

