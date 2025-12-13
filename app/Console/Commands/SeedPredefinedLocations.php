<?php

namespace App\Console\Commands;

use App\Data\PredefinedLocations;
use App\Data\CountryCodes;
use App\Services\LocationService;
use Illuminate\Console\Command;

class SeedPredefinedLocations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'locations:seed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed the locations table with all predefined locations from PredefinedLocations';

    /**
     * Execute the console command.
     */
    public function handle(LocationService $locationService): int
    {
        $this->info('Starting to seed predefined locations...');
        $this->newLine();

        $allLocations = PredefinedLocations::getAll();
        
        $totalCities = 0;
        foreach ($allLocations as $countries) {
            foreach ($countries as $cities) {
                $totalCities += count($cities);
            }
        }

        $createdCount = 0;
        $skippedCount = 0;

        $bar = $this->output->createProgressBar($totalCities);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar->setMessage('Processing locations...');

        foreach ($allLocations as $continent => $countries) {
            foreach ($countries as $country => $cities) {
                foreach ($cities as $city) {
                    $bar->setMessage("Processing: {$city}, {$country}");

                    try {
                        $existingLocation = \App\Models\Location::where('region_name', $city)
                            ->where('country', $country)
                            ->where('continent', $continent)
                            ->first();

                        if ($existingLocation) {
                            $skippedCount++;
                            if (!$existingLocation->country_code) {
                                $countryCode = CountryCodes::getCountryCode($country);
                                if ($countryCode) {
                                    $existingLocation->update(['country_code' => $countryCode]);
                                }
                            }
                        } else {
                            $countryCode = CountryCodes::getCountryCode($country);
                            $locationService->createOrFindLocation($city, $country, $continent, $countryCode);
                            $createdCount++;
                        }
                    } catch (\Exception $e) {
                        $this->newLine();
                        $this->error("Failed to create location: {$city}, {$country}, {$continent}");
                        $this->error("Error: {$e->getMessage()}");
                    }

                    $bar->advance();
                }
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Seeding completed!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Cities Processed', $totalCities],
                ['New Locations Created', $createdCount],
                ['Existing Locations Skipped', $skippedCount],
            ]
        );

        return Command::SUCCESS;
    }
}
