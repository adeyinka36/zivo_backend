<?php

namespace App\Services;

use App\Data\PredefinedLocations;
use App\Data\CountryCodes;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LocationService
{
    /**
     * Find a location in the database (locations must be added manually).
     *
     * @param string $city
     * @param string $country
     * @param string $continent
     * @param string|null $countryCode
     * @return Location|null Returns null if location not found
     */
    /**
     * Find a location in the database (locations must be added manually).
     *
     * @param string $city
     * @param string $country
     * @param string $continent
     * @param string|null $countryCode
     * @return Location|null
     */
    public function createOrFindLocation(
        string $city,
        string $country,
        string $continent,
        ?string $countryCode = null,
        float $latitude = 0,
        float $longitude = 0,
        string $timezone = 'UTC'
    ): ?Location {
        $location = Location::firstOrCreate(
            [
                'region_name' => $city,
                'country' => $country,
            ],
            [
                'continent' => $continent,
                'country_code' => $countryCode,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'timezone' => $timezone,
            ]
        );

        if (!$location->wasRecentlyCreated) {
            $updates = [];
            if (!$location->country_code && $countryCode) {
                $updates['country_code'] = $countryCode;
            }
            if ($location->continent !== $continent) {
                $updates['continent'] = $continent;
            }
            if (!empty($updates)) {
                $location->update($updates);
            }
        }

        return $location;
    }

    /**
     * Expand location selections to individual city locations using batch operations.
     *
     * @param array $selections Array of selection objects with type and identifier
     * @return array<string> Array of location IDs
     */
    public function expandLocationSelections(array $selections): array
    {
        $locationData = [];

        foreach ($selections as $selection) {
            $type = $selection['type'] ?? null;
            $identifier = $selection['identifier'] ?? null;

            if (!$type || !$identifier) {
                continue;
            }

            try {
                switch ($type) {
                    case 'city':
                        $locationData = array_merge($locationData, $this->collectCityData($identifier));
                        break;
                    case 'country':
                        $locationData = array_merge($locationData, $this->collectCountryData($identifier));
                        break;
                    case 'continent':
                        $locationData = array_merge($locationData, $this->collectContinentData($identifier));
                        break;
                }
            } catch (\Exception $e) {
                Log::warning('Failed to collect location data', [
                    'type' => $type,
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($locationData)) {
            return [];
        }

        return $this->batchCreateOrFindLocations($locationData);
    }

    /**
     * Collect city location data.
     *
     * @param string $cityIdentifier Format: "continent|country|city"
     * @return array<array{region_name: string, country: string, continent: string, country_code: string}>
     */
    private function collectCityData(string $cityIdentifier): array
    {
        $parts = explode('|', $cityIdentifier);
        if (count($parts) !== 3) {
            return [];
        }

        [$continent, $country, $city] = $parts;
        $countryCode = CountryCodes::getCountryCode($country);

        return [[
            'region_name' => $city,
            'country' => $country,
            'continent' => $continent,
            'country_code' => $countryCode,
        ]];
    }

    /**
     * Collect country location data.
     *
     * @param string $countryIdentifier Format: "continent|country"
     * @return array<array{region_name: string, country: string, continent: string, country_code: string}>
     */
    private function collectCountryData(string $countryIdentifier): array
    {
        $parts = explode('|', $countryIdentifier);
        if (count($parts) !== 2) {
            return [];
        }

        [$continent, $country] = $parts;
        $cities = PredefinedLocations::getCitiesByCountry($country);
        $countryCode = CountryCodes::getCountryCode($country);

        $locationData = [];
        foreach ($cities as $city) {
            $locationData[] = [
                'region_name' => $city,
                'country' => $country,
                'continent' => $continent,
                'country_code' => $countryCode,
            ];
        }

        return $locationData;
    }

    /**
     * Collect continent location data.
     *
     * @param string $continentIdentifier The continent name
     * @return array<array{region_name: string, country: string, continent: string, country_code: string}>
     */
    private function collectContinentData(string $continentIdentifier): array
    {
        $allLocations = PredefinedLocations::getAll();

        $locationData = [];
        foreach ($allLocations[$continentIdentifier] ?? [] as $country => $countryCities) {
            $countryCode = CountryCodes::getCountryCode($country);
            foreach ($countryCities as $city) {
                $locationData[] = [
                    'region_name' => $city,
                    'country' => $country,
                    'continent' => $continentIdentifier,
                    'country_code' => $countryCode,
                ];
            }
        }

        return $locationData;
    }

    /**
     * Batch create or find locations efficiently.
     *
     * @param array<array{region_name: string, country: string, continent: string, country_code: string}> $locationData
     * @return array<string> Array of location IDs
     */
    private function batchCreateOrFindLocations(array $locationData): array
    {
        if (empty($locationData)) {
            return [];
        }

        $uniqueLocations = [];
        $seen = [];

        foreach ($locationData as $data) {
            $key = $data['region_name'] . '|' . $data['country'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueLocations[] = $data;
            }
        }

        if (empty($uniqueLocations)) {
            return [];
        }

        return DB::transaction(function () use ($uniqueLocations) {
            $locationIds = [];
            $chunkSize = 500;

            foreach (array_chunk($uniqueLocations, $chunkSize) as $chunk) {
                $locationKeys = [];
                foreach ($chunk as $data) {
                    $locationKeys[] = [
                        'region_name' => $data['region_name'],
                        'country' => $data['country'],
                    ];
                }

                $existingLocations = Location::where(function ($query) use ($locationKeys) {
                    foreach ($locationKeys as $key) {
                        $query->orWhere(function ($q) use ($key) {
                            $q->where('region_name', $key['region_name'])
                              ->where('country', $key['country']);
                        });
                    }
                })->get()->keyBy(function ($location) {
                    return $location->region_name . '|' . $location->country;
                });

                $toUpdate = [];

                foreach ($chunk as $data) {
                    $key = $data['region_name'] . '|' . $data['country'];
                    $existing = $existingLocations->get($key);

                    if ($existing) {
                        $locationIds[] = $existing->id;

                        $updates = [];
                        if (!$existing->country_code && ($data['country_code'] ?? null)) {
                            $updates['country_code'] = $data['country_code'];
                        }
                        if ($existing->continent !== $data['continent']) {
                            $updates['continent'] = $data['continent'];
                        }

                        if (!empty($updates)) {
                            $toUpdate[$existing->id] = $updates;
                        }
                    } else {
                        $location = Location::create([
                            'region_name' => $data['region_name'],
                            'country' => $data['country'],
                            'continent' => $data['continent'],
                            'country_code' => $data['country_code'] ?? null,
                            'latitude' => 0,
                            'longitude' => 0,
                            'timezone' => 'UTC',
                        ]);
                        $locationIds[] = $location->id;
                    }
                }

                if (!empty($toUpdate)) {
                    foreach ($toUpdate as $id => $updates) {
                        Location::where('id', $id)->update($updates);
                    }
                }
            }

            return array_values(array_unique($locationIds));
        });
    }
}

