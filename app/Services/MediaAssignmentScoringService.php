<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Database\Eloquent\Builder;

/**
 * Service for calculating user scores for media assignment using point-based system.
 * 
 * Point weights:
 * - Location match: 60 points
 * - Age match: 5 points (only if age filter exists)
 * - Gender match: 5 points (only if gender filter exists and not "both")
 * - Each interest match: 10 points (max 3 matches = 30 points)
 * - Total max: 100 points
 */
class MediaAssignmentScoringService
{
    private const LOCATION_POINTS = 60;
    private const AGE_POINTS = 5;
    private const GENDER_POINTS = 5;
    private const INTEREST_POINTS = 10;
    private const MAX_INTEREST_MATCHES = 3;
    private const MAX_INTEREST_POINTS = 30;

    /**
     * Add point-based scoring to a user query.
     *
     * @param Builder $query
     * @param Media $media
     * @param array|null $locationCoordinates Array of ['latitude' => float, 'longitude' => float] for location-based, or null for radius-based
     * @param float|null $centerLatitude Center latitude for radius-based proximity (null if location-based)
     * @param float|null $centerLongitude Center longitude for radius-based proximity (null if location-based)
     * @param float|null $radiusKm Radius in kilometers (null if location-based)
     * @return Builder
     */
    public function addScoringToQuery(
        Builder $query,
        Media $media,
        ?array $locationCoordinates = null,
        ?float $centerLatitude = null,
        ?float $centerLongitude = null,
        ?float $radiusKm = null
    ): Builder {
        $query->selectRaw('users.*');

        $this->addLocationScore($query, $locationCoordinates, $centerLatitude, $centerLongitude, $radiusKm);
        $this->addAgeScore($query, $media);
        $this->addGenderScore($query, $media);
        $this->addInterestScore($query, $media);

        return $query;
    }

    /**
     * Add location score (60 points if user is within location range).
     *
     * @param Builder $query
     * @param array|null $locationCoordinates
     * @param float|null $centerLatitude
     * @param float|null $centerLongitude
     * @param float|null $radiusKm
     * @return void
     */
    private function addLocationScore(
        Builder $query,
        ?array $locationCoordinates,
        ?float $centerLatitude,
        ?float $centerLongitude,
        ?float $radiusKm
    ): void {
        if ($locationCoordinates !== null && !empty($locationCoordinates) && $radiusKm !== null) {
            $this->addLocationDistanceCondition($query, $locationCoordinates, $radiusKm);
            
            $query->selectRaw("
                CASE
                    WHEN users.latitude IS NOT NULL 
                         AND users.longitude IS NOT NULL 
                         AND users.latitude != 0 
                         AND users.longitude != 0 THEN ?
                    ELSE 0
                END as location_score
            ", [self::LOCATION_POINTS]);
        } elseif ($centerLatitude !== null && $centerLongitude !== null && $radiusKm !== null) {
            $query->whereRaw(
                "6371 * acos(
                    GREATEST(-1, LEAST(1,
                        cos(radians(?)) * cos(radians(users.latitude)) *
                        cos(radians(users.longitude) - radians(?)) +
                        sin(radians(?)) * sin(radians(users.latitude))
                    ))
                ) <= ?",
                [$centerLatitude, $centerLongitude, $centerLatitude, $radiusKm]
            );
            
            $query->selectRaw("
                CASE
                    WHEN users.latitude IS NOT NULL 
                         AND users.longitude IS NOT NULL 
                         AND users.latitude != 0 
                         AND users.longitude != 0 THEN ?
                    ELSE 0
                END as location_score
            ", [self::LOCATION_POINTS]);
        } else {
            $query->selectRaw('0 as location_score');
        }
    }

    /**
     * Build SQL condition for checking if user is within radius of any location coordinate.
     *
     * @param Builder $query
     * @param array $coordinates
     * @param float $radiusKm
     * @return void
     */
    private function addLocationDistanceCondition(Builder $query, array $coordinates, float $radiusKm): void
    {
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
    }

    /**
     * Add age score (5 points if age filter exists and user matches).
     *
     * @param Builder $query
     * @param Media $media
     * @return void
     */
    private function addAgeScore(Builder $query, Media $media): void
    {
        if ($media->age_from !== null || $media->age_to !== null) {
            $ageConditions = [];
            if ($media->age_from !== null) {
                $ageConditions[] = "DATE_PART('year', AGE(users.dob)) >= {$media->age_from}";
            }
            if ($media->age_to !== null) {
                $ageConditions[] = "DATE_PART('year', AGE(users.dob)) <= {$media->age_to}";
            }
            $ageCondition = !empty($ageConditions) ? '(' . implode(' AND ', $ageConditions) . ')' : 'true';
            $query->selectRaw("
                CASE
                    WHEN users.dob IS NOT NULL AND $ageCondition THEN ?
                    ELSE 0
                END as age_score
            ", [self::AGE_POINTS]);
        } else {
            $query->selectRaw('0 as age_score');
        }
    }

    /**
     * Add gender score (5 points if gender filter exists and user matches).
     *
     * @param Builder $query
     * @param Media $media
     * @return void
     */
    private function addGenderScore(Builder $query, Media $media): void
    {
        if ($media->gender && $media->gender !== 'both') {
            $genderValue = $media->gender;
            $query->selectRaw("
                CASE
                    WHEN users.gender = '$genderValue' THEN ?
                    ELSE 0
                END as gender_score
            ", [self::GENDER_POINTS]);
        } else {
            $query->selectRaw('0 as gender_score');
        }
    }

    /**
     * Add interest score (10 points per matching interest, max 30 points).
     *
     * @param Builder $query
     * @param Media $media
     * @return void
     */
    private function addInterestScore(Builder $query, Media $media): void
    {
        $mediaInterestIds = $media->interests()->pluck('interests.id')->toArray();
        
        if (empty($mediaInterestIds)) {
            $query->selectRaw('0 as interest_score');
            return;
        }

        $interestIdsStr = implode(',', array_map('intval', $mediaInterestIds));
        $maxScore = min(count($mediaInterestIds), self::MAX_INTEREST_MATCHES) * self::INTEREST_POINTS;

        $query->selectRaw("
            LEAST(
                (SELECT COUNT(*) FROM user_interests 
                 WHERE user_interests.user_id = users.id 
                 AND user_interests.interest_id IN ($interestIdsStr)) * ?,
                ?
            ) as interest_score
        ", [self::INTEREST_POINTS, $maxScore]);
    }

    /**
     * Order query by total score descending.
     *
     * @param Builder $query
     * @return Builder
     */
    public function orderByScore(Builder $query): Builder
    {
        return $query->orderByRaw('(location_score + age_score + gender_score + interest_score) DESC');
    }
}
