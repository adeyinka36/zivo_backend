<?php

namespace App\Services;

use App\Jobs\AssignMediaToUsersJob;
use App\Models\Media;
use App\Models\User;
use App\Models\UserAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for handling media assignment to users.
 * 
 * Dispatches the unified AssignMediaToUsersJob for assignment processing.
 * 
 * @deprecated Direct assignment logic moved to AssignMediaToUsersJob.
 * This service now acts as a wrapper for backward compatibility.
 */
class MediaAssignmentService
{
    /**
     * Dispatch job to assign media to users.
     *
     * @param Media $media
     * @return int Returns 0 (actual count determined by job)
     */
    public function assignMediaToUsers(Media $media): int
    {
        if (!$media->relationLoaded('locations')) {
            $media->load('locations');
        }

        $targetCount = (int) config('services.media.target_views', 10);
        $currentCount = $media->assignments()->where('current', true)->count();
        $neededCount = max(0, $targetCount - $currentCount);

        if ($neededCount === 0) {
            Log::info('Media already has sufficient assignments', [
                'media_id' => $media->id,
                'current_count' => $currentCount,
                'target_count' => $targetCount,
            ]);
            return 0;
        }

        AssignMediaToUsersJob::dispatch($media->id)->afterCommit();

        Log::info('AssignMediaToUsersJob dispatched via MediaAssignmentService', [
            'media_id' => $media->id,
            'needed_count' => $neededCount,
        ]);

        return 0;
    }

    /**
     * Select users based on location priority using coordinate-based radius search.
     *
     * @param Media $media
     * @param int $neededCount
     * @param array $excludeUserIds
     * @param string $creatorId
     * @param UserScoringService $scoringService
     * @return array
     */
    private function selectUsers(Media $media, int $neededCount, array $excludeUserIds, string $creatorId, UserScoringService $scoringService): array
    {
        $selectedUsers = [];
        $excludeUserIds = array_unique(array_merge($excludeUserIds, [$creatorId]));

        if ($media->locations->isEmpty()) {
            return $this->getUsers($media, $neededCount, $excludeUserIds, $scoringService);
        }

        $coordinates = $this->extractCoordinates($media->locations);
        
        if (empty($coordinates)) {
            return $this->getUsers($media, $neededCount, $excludeUserIds, $scoringService);
        }

        $radii = [5, 10, 20, 50, 100, 250, 500, 1000];

        foreach ($radii as $radiusKm) {
            if (count($selectedUsers) >= $neededCount) {
                break;
            }

            $remaining = $neededCount - count($selectedUsers);
            $radiusUsers = $this->getUsersWithinRadius($media, $coordinates, $radiusKm, $remaining, $excludeUserIds, $scoringService);
            
            $selectedUsers = array_merge($selectedUsers, $radiusUsers);
            $excludeUserIds = array_unique(array_merge($excludeUserIds, array_column($radiusUsers, 'id')));

            Log::info('selected--->', $selectedUsers);
        }

        if (count($selectedUsers) < $neededCount) {
            $remaining = $neededCount - count($selectedUsers);
            $generalUsers = $this->getUsers($media, $remaining, $excludeUserIds, $scoringService);
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
     * Get users within specified radius of any coordinate point using scoring system.
     *
     * @param Media $media
     * @param array $coordinates Array of ['latitude' => float, 'longitude' => float]
     * @param float $radiusKm Radius in kilometers
     * @param int $limit Maximum number of users to return
     * @param array $excludeUserIds User IDs to exclude
     * @param UserScoringService $scoringService
     * @return array
     */
    private function getUsersWithinRadius(Media $media, array $coordinates, float $radiusKm, int $limit, array $excludeUserIds, UserScoringService $scoringService): array
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
                    GREATEST(-1, LEAST(1,
                        cos(radians(?)) * cos(radians(users.latitude)) *
                        cos(radians(users.longitude) - radians(?)) +
                        sin(radians(?)) * sin(radians(users.latitude))
                    ))
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
                            GREATEST(-1, LEAST(1,
                                cos(radians(?)) * cos(radians(users.latitude)) *
                                cos(radians(users.longitude) - radians(?)) +
                                sin(radians(?)) * sin(radians(users.latitude))
                            ))
                        ) <= ?",
                        [$lat, $lng, $lat, $radiusKm]
                    );
                }
            });
        }

        if (count($coordinates) === 1) {
            $coord = $coordinates[0];
            $lat = (float) $coord['latitude'];
            $lng = (float) $coord['longitude'];
            $scoringService->addScoringToQuery($query, $media, $lat, $lng, $radiusKm);
        } else {
            $scoringService->addScoringToQuery($query, $media, null, null, null);
        }

        $scoringService->orderByScore($query);

        return $query->limit($limit)->get()->toArray();
    }

    /**
     * Get verified users without location restriction using scoring system.
     *
     * @param Media $media
     * @param int $limit
     * @param array $excludeUserIds
     * @param UserScoringService $scoringService
     * @return array
     */
    private function getUsers(Media $media, int $limit, array $excludeUserIds, UserScoringService $scoringService): array
    {
        $query = User::whereNotNull('email_verified_at')
            ->whereNotIn('id', $excludeUserIds);

        $scoringService->addScoringToQuery($query, $media, null, null, null);
        $scoringService->orderByScore($query);

        return $query->limit($limit)->get()->toArray();
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

