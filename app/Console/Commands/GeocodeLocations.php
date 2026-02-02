<?php

namespace App\Console\Commands;

use App\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocodeLocations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'locations:geocode 
                            {--dry-run : Preview what would be updated without making changes}
                            {--skip-valid : Skip locations that already have valid coordinates}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Geocode all locations in the database using OpenStreetMap Nominatim API';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $skipValid = $this->option('skip-valid');

        if ($isDryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $query = Location::query();

        if ($skipValid) {
            $query->where(function ($q) {
                $q->where('latitude', 0)
                  ->orWhere('longitude', 0)
                  ->orWhereNull('latitude')
                  ->orWhereNull('longitude');
            });
        }

        $locations = $query->get();
        $total = $locations->count();

        if ($total === 0) {
            $this->info('No locations found to geocode.');
            return Command::SUCCESS;
        }

        $this->info("Found {$total} location(s) to geocode.");
        $this->newLine();

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $bar->setMessage('Starting...');
        $bar->start();

        $successCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($locations as $location) {
            $bar->setMessage("Geocoding: {$location->region_name}, {$location->country}");

            try {
                $coordinates = $this->geocodeLocation($location);

                if ($coordinates) {
                    if (!$isDryRun) {
                        $location->update([
                            'latitude' => $coordinates['latitude'],
                            'longitude' => $coordinates['longitude'],
                        ]);
                    }

                    $successCount++;
                    $bar->setMessage("✓ {$location->region_name}");
                } else {
                    $failedCount++;
                    $bar->setMessage("✗ {$location->region_name} (no results)");
                    Log::warning('Geocoding failed: No results', [
                        'location_id' => $location->id,
                        'region_name' => $location->region_name,
                        'country' => $location->country,
                    ]);
                }
            } catch (\Exception $e) {
                $failedCount++;
                $bar->setMessage("✗ {$location->region_name} (error)");
                Log::error('Geocoding error', [
                    'location_id' => $location->id,
                    'region_name' => $location->region_name,
                    'country' => $location->country,
                    'error' => $e->getMessage(),
                ]);
            }

            $bar->advance();

            if (!$isDryRun) {
                sleep(1);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Status', 'Count'],
            [
                ['Success', $successCount],
                ['Failed', $failedCount],
                ['Skipped', $skippedCount],
                ['Total', $total],
            ]
        );

        if ($isDryRun) {
            $this->newLine();
            $this->info('This was a dry run. Run without --dry-run to apply changes.');
        }

        return Command::SUCCESS;
    }

    /**
     * Geocode a location using OpenStreetMap Nominatim API.
     *
     * @param Location $location
     * @return array|null Array with 'latitude' and 'longitude', or null if not found
     */
    private function geocodeLocation(Location $location): ?array
    {
        $queries = $this->buildGeocodeQueries($location);

        foreach ($queries as $queryData) {
            try {
                $params = [
                    'q' => $queryData['query'],
                    'format' => 'json',
                    'limit' => 5,
                    'addressdetails' => 1,
                ];

                if ($queryData['country_code']) {
                    $params['countrycodes'] = strtolower($queryData['country_code']);
                }

                $response = Http::timeout(10)
                    ->withHeaders([
                        'User-Agent' => 'ZivoApp/1.0 (Geocoding Service)',
                    ])
                    ->get('https://nominatim.openstreetmap.org/search', $params)
                    ->json();

                if (!empty($response) && is_array($response)) {
                    $result = $this->findBestMatch($response, $location);
                    if ($result) {
                        return $result;
                    }
                }
            } catch (\Exception $e) {
                Log::debug('Nominatim query attempt failed', [
                    'location_id' => $location->id,
                    'query' => $queryData['query'],
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        Log::debug('Nominatim no results after all attempts', [
            'location_id' => $location->id,
            'region_name' => $location->region_name,
            'country' => $location->country,
        ]);

        return null;
    }

    /**
     * Find the best matching result from Nominatim response.
     *
     * @param array $results
     * @param Location $location
     * @return array|null
     */
    private function findBestMatch(array $results, Location $location): ?array
    {
        foreach ($results as $result) {
            if (!isset($result['lat']) || !isset($result['lon'])) {
                continue;
            }

            $lat = (float) $result['lat'];
            $lon = (float) $result['lon'];

            if ($lat == 0.0 && $lon == 0.0) {
                continue;
            }

            if (abs($lat) > 90 || abs($lon) > 180) {
                continue;
            }

            $address = $result['address'] ?? [];
            $resultCity = $this->extractCityFromAddress($address);
            $resultCountry = $address['country'] ?? '';

            if ($this->isGoodMatch($resultCity, $resultCountry, $location)) {
                return [
                    'latitude' => $lat,
                    'longitude' => $lon,
                ];
            }
        }

        if (!empty($results) && isset($results[0]['lat']) && isset($results[0]['lon'])) {
            $lat = (float) $results[0]['lat'];
            $lon = (float) $results[0]['lon'];

            if ($lat != 0.0 && $lon != 0.0 && abs($lat) <= 90 && abs($lon) <= 180) {
                return [
                    'latitude' => $lat,
                    'longitude' => $lon,
                ];
            }
        }

        return null;
    }

    /**
     * Check if a result is a good match for the location.
     *
     * @param string|null $resultCity
     * @param string $resultCountry
     * @param Location $location
     * @return bool
     */
    private function isGoodMatch(?string $resultCity, string $resultCountry, Location $location): bool
    {
        if ($resultCity && $location->region_name) {
            $cityMatch = strcasecmp($resultCity, $location->region_name) === 0 ||
                        stripos($resultCity, $location->region_name) !== false ||
                        stripos($location->region_name, $resultCity) !== false;
        } else {
            $cityMatch = true;
        }

        $countryMatch = empty($resultCountry) || 
                       stripos($resultCountry, $location->country) !== false ||
                       stripos($location->country, $resultCountry) !== false;

        return $cityMatch && $countryMatch;
    }

    /**
     * Extract city name from Nominatim address object.
     *
     * @param array $address
     * @return string|null
     */
    private function extractCityFromAddress(array $address): ?string
    {
        return $address['city'] ?? 
               $address['town'] ?? 
               $address['village'] ?? 
               $address['municipality'] ?? 
               $address['county'] ?? 
               null;
    }

    /**
     * Build multiple geocoding query variations to try.
     *
     * @param Location $location
     * @return array Array of query data with 'query' and 'country_code' keys
     */
    private function buildGeocodeQueries(Location $location): array
    {
        $queries = [];
        $city = $location->region_name;
        $country = $location->country;
        $countryCode = $location->country_code;

        if (!$city || $city === 'Unknown') {
            return [];
        }

        $normalizedCountry = $this->normalizeCountryName($country);

        if ($countryCode && $countryCode !== 'XX') {
            $queries[] = [
                'query' => "{$city}, {$normalizedCountry}",
                'country_code' => $countryCode,
            ];

            $queries[] = [
                'query' => "{$city}",
                'country_code' => $countryCode,
            ];
        }

        if ($normalizedCountry && $normalizedCountry !== 'Unknown') {
            $queries[] = [
                'query' => "{$city}, {$normalizedCountry}",
                'country_code' => null,
            ];
        }

        $queries[] = [
            'query' => $city,
            'country_code' => null,
        ];

        return array_unique($queries, SORT_REGULAR);
    }

    /**
     * Normalize country name for better matching.
     *
     * @param string|null $country
     * @return string
     */
    private function normalizeCountryName(?string $country): string
    {
        if (!$country || $country === 'Unknown') {
            return 'Unknown';
        }

        $normalizations = [
            'United Kingdom of Great Britain and Northern Ireland (the)' => 'United Kingdom',
            'United Kingdom of Great Britain and Northern Ireland' => 'United Kingdom',
            'United States of America' => 'United States',
            'USA' => 'United States',
            'US' => 'United States',
        ];

        $normalized = $normalizations[$country] ?? $country;

        if (preg_match('/\(the\)$/', $normalized)) {
            $normalized = trim(str_replace('(the)', '', $normalized));
        }

        return $normalized;
    }
}
