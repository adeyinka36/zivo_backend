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
     * Create or find a location in the database.
     *
     * @param string $city
     * @param string $country
     * @param string $continent
     * @return Location
     */
    public function createOrFindLocation(string $city, string $country, string $continent, ?string $countryCode = null): Location
    {
        return DB::transaction(function () use ($city, $country, $continent, $countryCode) {
            $location = Location::where('region_name', $city)
                ->where('country', $country)
                ->first();

            if ($location) {
                if (!$location->country_code && $countryCode) {
                    $location->update(['country_code' => $countryCode]);
                }
                if ($location->continent !== $continent) {
                    $location->update(['continent' => $continent]);
                }
                return $location;
            }

            $code = $countryCode ?? CountryCodes::getCountryCode($country);

            return Location::create([
                'region_name' => $city,
                'country' => $country,
                'country_code' => $code,
                'continent' => $continent,
                'latitude' => 0,
                'longitude' => 0,
                'timezone' => 'UTC',
            ]);
        });
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

                $toCreate = [];
                $toUpdate = [];

                foreach ($chunk as $data) {
                    $key = $data['region_name'] . '|' . $data['country'];
                    $existing = $existingLocations->get($key);

                    if ($existing) {
                        $locationIds[] = $existing->id;

                        $updates = [];
                        if (!$existing->country_code && $data['country_code']) {
                            $updates['country_code'] = $data['country_code'];
                        }
                        if ($existing->continent !== $data['continent']) {
                            $updates['continent'] = $data['continent'];
                        }

                        if (!empty($updates)) {
                            $toUpdate[$existing->id] = $updates;
                        }
                    } else {
                        $toCreate[] = [
                            'region_name' => $data['region_name'],
                            'country' => $data['country'],
                            'country_code' => $data['country_code'],
                            'continent' => $data['continent'],
                            'latitude' => 0,
                            'longitude' => 0,
                            'timezone' => 'UTC',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }

                if (!empty($toCreate)) {
                    Location::insert($toCreate);

                    $newLocations = Location::whereIn('region_name', array_column($toCreate, 'region_name'))
                        ->whereIn('country', array_column($toCreate, 'country'))
                        ->pluck('id')
                        ->toArray();

                    $locationIds = array_merge($locationIds, $newLocations);
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

