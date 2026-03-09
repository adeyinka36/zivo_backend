<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Database\Eloquent\Builder;

/**
 * Service for calculating user scores for media assignment prioritization.
 *
 * Uses a tiered scoring system:
 * - Tier 1 (Highest): All criteria match (Location + Interests + Gender)
 * - Tier 2: Location + Interests
 * - Tier 3: Location only
 * - Tier 4: Interests only
 * - Tier 5: Gender only
 * - Tier 6 (Lowest): No filters
 *
 * Within each tier, users are further ranked by:
 * - Interest match count (more matches = higher score)
 * - Location proximity (closer = higher score, for radius-based)
 */
class UserScoringService
{
    /**
     * Tier score multipliers.
     */
    private const TIER_SCORES = [
        'all_criteria' => 1000000,
        'location_interests' => 800000,
        'location_only' => 600000,
        'interests_only' => 600000,
        'gender_only' => 200000,
        'none' => 0,
    ];

    /**
     * Interest match score per match (max 3 matches = 30000).
     */
    private const INTEREST_MATCH_SCORE = 10000;

    /**
     * Maximum location proximity score (for users at 0km distance).
     */
    private const MAX_LOCATION_SCORE = 10000;

    /**
     * Random tiebreaker range.
     */
    private const RANDOM_SCORE_MAX = 100;

    /**
     * Add scoring calculations to a user query.
     *
     * @param Builder $query
     * @param Media $media
     * @param float|null $centerLatitude Center latitude for radius-based proximity (null if not applicable)
     * @param float|null $centerLongitude Center longitude for radius-based proximity (null if not applicable)
     * @param float|null $maxRadius Maximum radius in km for proximity normalization (null if not applicable)
     * @return Builder
     */
    public function addScoringToQuery(
        Builder $query,
        Media $media,
        ?float $centerLatitude = null,
        ?float $centerLongitude = null,
        ?float $maxRadius = null
    ): Builder {
        $mediaInterestIds = $media->interests()->pluck('interests.id')->toArray();
        $hasInterests = !empty($mediaInterestIds);
        $hasGenderFilter = $media->gender && $media->gender !== 'both';
        $hasLocationFilter = $centerLatitude !== null && $centerLongitude !== null;

        $query->selectRaw('users.*');

        $this->addTierScore($query, $media, $hasInterests, $hasGenderFilter, $hasLocationFilter);
        $this->addInterestMatchScore($query, $mediaInterestIds, $hasInterests);
        
        if ($hasLocationFilter && $maxRadius !== null) {
            $this->addLocationProximityScore($query, $centerLatitude, $centerLongitude, $maxRadius);
        } else {
            $query->selectRaw('0 as location_score');
        }

        $query->selectRaw('FLOOR(RANDOM() * ?) as random_score', [self::RANDOM_SCORE_MAX]);
        $query->selectRaw('(tier_score + interest_score + location_score + random_score) as total_score');

        return $query;
    }

    /**
     * Add tier score calculation based on criteria matches.
     *
     * @param Builder $query
     * @param Media $media
     * @param bool $hasInterests
     * @param bool $hasGenderFilter
     * @param bool $hasLocationFilter
     * @return void
     */
    private function addTierScore(
        Builder $query,
        Media $media,
        bool $hasInterests,
        bool $hasGenderFilter,
        bool $hasLocationFilter
    ): void {
        if ($hasLocationFilter) {
            $query->selectRaw("
                CASE
                    WHEN users.latitude IS NOT NULL AND users.longitude IS NOT NULL AND users.latitude != 0 AND users.longitude != 0 THEN true
                    ELSE false
                END as location_match
            ");
        } else {
            $query->selectRaw('true as location_match');
        }

        if ($hasInterests) {
            $mediaInterestIds = $media->interests()->pluck('interests.id')->toArray();
            $interestIdsStr = implode(',', array_map('intval', $mediaInterestIds));
            $query->selectRaw("
                EXISTS (SELECT 1 FROM user_interests WHERE user_interests.user_id = users.id AND user_interests.interest_id IN ($interestIdsStr)) as interests_match
            ");
        } else {
            $query->selectRaw('true as interests_match');
        }

        if ($hasGenderFilter) {
            $genderValue = $media->gender;
            $query->selectRaw("users.gender = '$genderValue' as gender_match");
        } else {
            $query->selectRaw('true as gender_match');
        }

        $query->selectRaw("
            CASE
                WHEN location_match AND interests_match AND gender_match THEN ?
                WHEN location_match AND interests_match THEN ?
                WHEN location_match THEN ?
                WHEN interests_match THEN ?
                WHEN gender_match THEN ?
                ELSE ?
            END as tier_score
        ", [
            self::TIER_SCORES['all_criteria'],
            self::TIER_SCORES['location_interests'],
            self::TIER_SCORES['location_only'],
            self::TIER_SCORES['interests_only'],
            self::TIER_SCORES['gender_only'],
            self::TIER_SCORES['none'],
        ]);
    }

    /**
     * Add interest match count score.
     *
     * @param Builder $query
     * @param array $mediaInterestIds
     * @param bool $hasInterests
     * @return void
     */
    private function addInterestMatchScore(Builder $query, array $mediaInterestIds, bool $hasInterests): void
    {
        if (!$hasInterests || empty($mediaInterestIds)) {
            $query->selectRaw('0 as interest_score');
            return;
        }

        $interestIdsStr = implode(',', array_map('intval', $mediaInterestIds));
        $maxMatches = min(count($mediaInterestIds), 3);
        $maxScore = $maxMatches * self::INTEREST_MATCH_SCORE;

        $query->selectRaw("
            LEAST(
                (SELECT COUNT(*) FROM user_interests 
                 WHERE user_interests.user_id = users.id 
                 AND user_interests.interest_id IN ($interestIdsStr)) * ?,
                ?
            ) as interest_score
        ", [self::INTEREST_MATCH_SCORE, $maxScore]);
    }

    /**
     * Add location proximity score (closer = higher score).
     *
     * @param Builder $query
     * @param float $centerLatitude
     * @param float $centerLongitude
     * @param float $maxRadius Maximum radius in km for normalization
     * @return void
     */
    private function addLocationProximityScore(
        Builder $query,
        float $centerLatitude,
        float $centerLongitude,
        float $maxRadius
    ): void {
        $query->selectRaw("
            CASE
                WHEN users.latitude IS NOT NULL AND users.longitude IS NOT NULL AND users.latitude != 0 AND users.longitude != 0 THEN
                    GREATEST(0, ? - (
                        (6371 * acos(
                            GREATEST(-1, LEAST(1,
                                cos(radians(?)) * cos(radians(users.latitude)) *
                                cos(radians(users.longitude) - radians(?)) +
                                sin(radians(?)) * sin(radians(users.latitude))
                            ))
                        )) * ? / ?
                    ))
                ELSE 0
            END as location_score
        ", [
            self::MAX_LOCATION_SCORE,
            $centerLatitude,
            $centerLongitude,
            $centerLatitude,
            self::MAX_LOCATION_SCORE,
            $maxRadius,
        ]);
    }

    /**
     * Order query by total score descending.
     *
     * @param Builder $query
     * @return Builder
     */
    public function orderByScore(Builder $query): Builder
    {
        return $query->orderByDesc('total_score');
    }
}
