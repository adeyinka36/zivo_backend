<?php

namespace App\Services;

use App\Data\PredefinedLocations;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LocationService
{
    /**
     * Create or find a location in the database.
     *
     * @param string $city
     * @param string $country
     * @param string $continent
     * @return Location
     */
    public function createOrFindLocation(string $city, string $country, string $continent): Location
    {
        return DB::transaction(function () use ($city, $country, $continent) {
            $location = Location::where('region_name', $city)
                ->where('country', $country)
                ->where('continent', $continent)
                ->first();

            if ($location) {
                return $location;
            }

            return Location::create([
                'region_name' => $city,
                'country' => $country,
                'continent' => $continent,
                'latitude' => 0,
                'longitude' => 0,
                'timezone' => 'UTC',
            ]);
        });
    }

    /**
     * Expand location selections to individual city locations.
     *
     * @param array $selections Array of selection objects with type and identifier
     * @return array<string> Array of location IDs
     */
    public function expandLocationSelections(array $selections): array
    {
        $locationIds = [];

        foreach ($selections as $selection) {
            $type = $selection['type'] ?? null;
            $identifier = $selection['identifier'] ?? null;

            if (!$type || !$identifier) {
                continue;
            }

            try {
                switch ($type) {
                    case 'city':
                        $locationIds = array_merge($locationIds, $this->handleCitySelection($identifier));
                        break;
                    case 'country':
                        $locationIds = array_merge($locationIds, $this->handleCountrySelection($identifier));
                        break;
                    case 'continent':
                        $locationIds = array_merge($locationIds, $this->handleContinentSelection($identifier));
                        break;
                }
            } catch (\Exception $e) {
                Log::warning('Failed to expand location selection', [
                    'type' => $type,
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return array_unique($locationIds);
    }

    /**
     * Handle city selection.
     *
     * @param string $cityIdentifier Format: "continent|country|city"
     * @return array<string>
     */
    private function handleCitySelection(string $cityIdentifier): array
    {
        $parts = explode('|', $cityIdentifier);
        if (count($parts) !== 3) {
            return [];
        }

        [$continent, $country, $city] = $parts;
        $location = $this->createOrFindLocation($city, $country, $continent);

        return [$location->id];
    }

    /**
     * Handle country selection.
     *
     * @param string $countryIdentifier Format: "continent|country"
     * @return array<string>
     */
    private function handleCountrySelection(string $countryIdentifier): array
    {
        $parts = explode('|', $countryIdentifier);
        if (count($parts) !== 2) {
            return [];
        }

        [$continent, $country] = $parts;
        $cities = PredefinedLocations::getCitiesByCountry($country);

        $locationIds = [];
        foreach ($cities as $city) {
            $location = $this->createOrFindLocation($city, $country, $continent);
            $locationIds[] = $location->id;
        }

        return $locationIds;
    }

    /**
     * Handle continent selection.
     *
     * @param string $continentIdentifier The continent name
     * @return array<string>
     */
    private function handleContinentSelection(string $continentIdentifier): array
    {
        $cities = PredefinedLocations::getCitiesByContinent($continentIdentifier);
        $allLocations = PredefinedLocations::getAll();

        $locationIds = [];
        foreach ($allLocations[$continentIdentifier] ?? [] as $country => $countryCities) {
            foreach ($countryCities as $city) {
                $location = $this->createOrFindLocation($city, $country, $continentIdentifier);
                $locationIds[] = $location->id;
            }
        }

        return $locationIds;
    }
}

