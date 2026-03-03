<?php

namespace Database\Seeders;

use App\Data\CountryCodes;
use App\Data\PredefinedLocations;
use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * Seed the locations table with predefined cities.
     * Uses firstOrCreate for idempotency - safe to run multiple times.
     */
    public function run(): void
    {
        $allLocations = PredefinedLocations::getAll();
        $created = 0;
        $skipped = 0;

        foreach ($allLocations as $continent => $countries) {
            foreach ($countries as $country => $cities) {
                $countryCode = CountryCodes::getCountryCode($country);

                foreach ($cities as $city) {
                    $location = Location::firstOrCreate(
                        [
                            'region_name' => $city,
                            'country' => $country,
                        ],
                        [
                            'continent' => $continent,
                            'country_code' => $countryCode,
                            'latitude' => 0,
                            'longitude' => 0,
                            'timezone' => 'UTC',
                        ]
                    );

                    if ($location->wasRecentlyCreated) {
                        $created++;
                    } else {
                        $skipped++;
                    }
                }
            }
        }

        $this->command?->info("Locations: {$created} created, {$skipped} already existed.");
    }
}
