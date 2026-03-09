<?php

namespace App\Jobs;

use App\Models\Media;
use App\Models\User;
use App\Models\UserAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job to assign media to users based on location filters.
 *
 * Uses proximity around configured location coordinates, with a sensible
 * radius expansion strategy. If no valid locations are present, it falls
 * back to random selection among verified users.
 */
class AssignMediaToUsersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    private const MAX_LOCATION_RADIUS = 500;
    private const INITIAL_RADIUS = 1;

    public function __construct(
        private readonly string $mediaId
    ) {}

    public function handle(): void
    {
        try {
            $media = Media::with(['creator', 'locations'])->findOrFail($this->mediaId);

            if (!$media) {
                Log::warning('Media not found for assignment', ['media_id' => $this->mediaId]);
                return;
            }

            if (!$media->active || $media->payment_status !== 'paid') {
                Log::info('Media not ready for assignment', [
                    'media_id' => $media->id,
                    'active' => $media->active,
                    'payment_status' => $media->payment_status,
                ]);
                return;
            }

            $now = now()->utc();
            if ($media->from_date && $media->to_date) {
                $fromDate = \Carbon\Carbon::parse($media->from_date)->utc();
                $toDate = \Carbon\Carbon::parse($media->to_date)->utc();
                
                if ($now->lt($fromDate) || $now->gt($toDate)) {
                    Log::info('Media is outside date range', [
                        'media_id' => $media->id,
                        'from_date' => $fromDate->toIso8601String(),
                        'to_date' => $toDate->toIso8601String(),
                        'current_time' => $now->toIso8601String(),
                    ]);
                    return;
                }
            }

            $targetViews = (int) config('services.media.target_views', 10);
            $currentRound = $media->rounds_completed + 1;
            
            if ($currentRound > $media->rounds) {
                Log::info('All rounds completed for media', [
                    'media_id' => $media->id,
                    'total_rounds' => $media->rounds,
                    'completed_rounds' => $media->rounds_completed,
                ]);
                return;
            }

            $watchedCount = $media->watchedByUsers()->count();
            $roundsCompleted = (int) floor($watchedCount / $targetViews);
            
            if ($roundsCompleted >= $currentRound) {
                Log::info('Current round already completed, will assign next round on next job run', [
                    'media_id' => $media->id,
                    'current_round' => $currentRound,
                    'rounds_completed' => $roundsCompleted,
                    'watched_count' => $watchedCount,
                ]);
                return;
            }

            $desiredCount = $targetViews;
            $currentCount = $media->assignments()->count();
            $neededCount = max(0, $desiredCount - $currentCount);

            if ($neededCount === 0) {
                Log::info('Media already has sufficient assignments', [
                    'media_id' => $media->id,
                    'current_count' => $currentCount,
                    'desired_count' => $desiredCount,
                ]);
                return;
            }

            $assignedUserIds = $media->assignments()->pluck('user_id')->toArray();
            // Creators are separate from users, so no need to exclude creator_id
            $excludeUserIds = $assignedUserIds;

            $selectedUsers = $this->selectUsers($media, $neededCount, $excludeUserIds);

            if (empty($selectedUsers)) {
                Log::warning('No eligible users found for media assignment', [
                    'media_id' => $media->id,
                    'needed_count' => $neededCount,
                ]);
                return;
            }

            $this->createAssignments($media, $selectedUsers);

            Log::info('Media assigned to users successfully', [
                'media_id' => $media->id,
                'assigned_count' => count($selectedUsers),
                'total_assignments' => $currentCount + count($selectedUsers),
            ]);

        } catch (Throwable $e) {
            Log::error('Failed to assign media to users', [
                'media_id' => $this->mediaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Select users using progressive radius expansion around the media's location.
     *
     * Strategy:
     * 1. If no location context, select random verified users.
     * 2. Expand radius from INITIAL_RADIUS (1 km) doubling each step up to MAX_LOCATION_RADIUS (500 km).
     * 3. If radius expansion still hasn't found enough users, fall back to random verified users.
     *
     * @param Media $media
     * @param int $neededCount
     * @param array $excludeUserIds
     * @return array
     */
    private function selectUsers(
        Media $media,
        int $neededCount,
        array $excludeUserIds
    ): array {
        $locationContext = $this->buildLocationContext($media);

        if ($locationContext === null) {
            return $this->selectUsersWithoutLocation($media, $neededCount, $excludeUserIds);
        }

        $selectedUsers = [];
        $currentRadius = $locationContext['initial_radius'];

        while (count($selectedUsers) < $neededCount && $currentRadius <= self::MAX_LOCATION_RADIUS) {
            $remaining = $neededCount - count($selectedUsers);
            $radiusUsers = $this->selectUsersWithinRadius(
                $media,
                $locationContext['center_latitude'],
                $locationContext['center_longitude'],
                $currentRadius,
                $remaining,
                $excludeUserIds
            );

            $selectedUsers = array_merge($selectedUsers, $radiusUsers);
            $excludeUserIds = array_unique(array_merge($excludeUserIds, array_column($radiusUsers, 'id')));

            if (count($selectedUsers) >= $neededCount) {
                break;
            }

            $currentRadius *= 2;
        }

        if (count($selectedUsers) < $neededCount) {
            $remaining = $neededCount - count($selectedUsers);
            $fallbackUsers = $this->selectUsersWithoutLocation($media, $remaining, $excludeUserIds);
            $selectedUsers = array_merge($selectedUsers, $fallbackUsers);

            Log::info('Used random fallback after radius exhaustion', [
                'media_id' => $media->id,
                'location_matched' => count($selectedUsers) - count($fallbackUsers),
                'random_filled' => count($fallbackUsers),
            ]);
        }

        return array_slice($selectedUsers, 0, $neededCount);
    }

    /**
     * Build location context for the media.
     *
     * @param Media $media
     * @return array|null Returns location context with center coordinates and initial radius, or null if no location requirement
     */
    private function buildLocationContext(Media $media): ?array
    {
        if ($media->locations && $media->locations->isNotEmpty()) {
            $location = $media->locations->first();
            $lat = (float) $location->latitude;
            $lng = (float) $location->longitude;

            if ($lat != 0.0 && $lng != 0.0 && abs($lat) <= 90 && abs($lng) <= 180) {
                return [
                    'center_latitude' => $lat,
                    'center_longitude' => $lng,
                    'initial_radius' => self::INITIAL_RADIUS,
                ];
            }
        }

        return null;
    }

    /**
     * Select users within specified radius with gender filter applied.
     *
     * @param Media $media
     * @param float $centerLatitude
     * @param float $centerLongitude
     * @param float $radiusKm
     * @param int $limit
     * @param array $excludeUserIds
     * @return array
     */
    private function selectUsersWithinRadius(
        Media $media,
        float $centerLatitude,
        float $centerLongitude,
        float $radiusKm,
        int $limit,
        array $excludeUserIds
    ): array {
        if ($limit <= 0) {
            return [];
        }

        $query = User::query()
            ->whereNotNull('email_verified_at')
            ->whereNotIn('id', $excludeUserIds)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('latitude', '!=', 0)
            ->where('longitude', '!=', 0)
            ->whereRaw(
                "6371 * acos(
                    GREATEST(-1, LEAST(1,
                        cos(radians(?)) * cos(radians(users.latitude)) *
                        cos(radians(users.longitude) - radians(?)) +
                        sin(radians(?)) * sin(radians(users.latitude))
                    ))
                ) <= ?",
                [$centerLatitude, $centerLongitude, $centerLatitude, $radiusKm]
            );

        return $query
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Select users without location requirement.
     *
     * @param Media $media
     * @param int $neededCount
     * @param array $excludeUserIds
     * @return array
     */
    private function selectUsersWithoutLocation(
        Media $media,
        int $neededCount,
        array $excludeUserIds
    ): array {
        $query = User::query()
            ->whereNotNull('email_verified_at')
            ->whereNotIn('id', $excludeUserIds);

        return $query
            ->inRandomOrder()
            ->limit($neededCount)
            ->get()
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
