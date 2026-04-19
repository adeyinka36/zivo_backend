<?php

namespace App\Http\Resources;

use App\Models\Campaign;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class DiscoverCampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Campaign $campaign */
        $campaign = $this->resource;
        /** @var Media $media */
        $media = $campaign->media;

        $creator = $media->relationLoaded('creator') ? $media->creator : null;
        $businessName = $creator?->business_name ?? $creator?->name ?? 'Unknown business';

        return [
            'id' => $campaign->id,
            'media_id' => $media->id,
            'name' => $media->name,
            'business_name' => $businessName,
            'location_summary' => $this->locationSummary($media),
            'locations' => $this->locationDetails($media),
            'offer' => $media->offer,
            'description' => $media->description,
            'from_date' => $media->from_date,
            'to_date' => $media->to_date,
            'rounds' => $media->rounds,
            'rounds_completed' => $media->rounds_completed,
        ];
    }

    /**
     * @return array<int, array{region_name: string|null, country: string|null}>
     */
    private function locationDetails(Media $media): array
    {
        if ($media->relationLoaded('locations') && $media->locations->isNotEmpty()) {
            return $media->locations->map(fn ($loc) => [
                'region_name' => $loc->region_name,
                'country' => $loc->country,
            ])->values()->all();
        }

        $selections = $media->location_selections;
        if (! is_array($selections) || $selections === []) {
            return [];
        }

        return collect($selections)->map(fn ($row) => [
            'region_name' => is_array($row) && isset($row['identifier']) ? (string) $row['identifier'] : null,
            'country' => null,
        ])->values()->all();
    }

    private function locationSummary(Media $media): string
    {
        if ($media->relationLoaded('locations') && $media->locations->isNotEmpty()) {
            /** @var Collection<int, mixed> $names */
            $names = $media->locations->pluck('region_name')->filter();

            return $names->isNotEmpty() ? $names->unique()->implode(', ') : 'Various locations';
        }

        $selections = $media->location_selections;
        if (is_array($selections) && $selections !== []) {
            $parts = collect($selections)
                ->map(fn ($row) => is_array($row) && isset($row['identifier']) ? (string) $row['identifier'] : null)
                ->filter();

            return $parts->isNotEmpty() ? $parts->unique()->implode(', ') : 'Various locations';
        }

        return 'Various locations';
    }
}
