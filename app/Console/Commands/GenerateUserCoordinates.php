<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateUserCoordinates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:generate-coordinates 
                            {--dry-run : Preview what would be updated without making changes}
                            {--all : Update all users, not just those missing coordinates}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate realistic latitude and longitude coordinates for users missing them';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $updateAll = $this->option('all');

        if ($isDryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $query = User::query()
            ->with('location');

        if (!$updateAll) {
            $query->where(function ($q) {
                $q->whereNull('latitude')
                  ->orWhereNull('longitude')
                  ->orWhere('latitude', 0)
                  ->orWhere('longitude', 0);
            });
        }

        $users = $query->get();
        $total = $users->count();

        if ($total === 0) {
            $this->info('No users found to update.');
            return Command::SUCCESS;
        }

        $this->info("Found {$total} user(s) to process.");
        $this->newLine();

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $bar->setMessage('Starting...');
        $bar->start();

        $updatedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        foreach ($users as $user) {
            $bar->setMessage("Processing: {$user->email}");

            try {
                $coordinates = $this->getCoordinatesForUser($user);

                if ($coordinates) {
                    if (!$isDryRun) {
                        $user->update([
                            'latitude' => $coordinates['latitude'],
                            'longitude' => $coordinates['longitude'],
                        ]);
                    }

                    $updatedCount++;
                    $bar->setMessage("✓ {$user->email}");
                } else {
                    $skippedCount++;
                    $bar->setMessage("⊘ {$user->email} (no location)");
                }
            } catch (\Exception $e) {
                $errorCount++;
                $bar->setMessage("✗ {$user->email} (error)");
                \Log::error('Failed to generate coordinates for user', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Status', 'Count'],
            [
                ['Updated', $updatedCount],
                ['Skipped', $skippedCount],
                ['Errors', $errorCount],
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
     * Get coordinates for a user.
     * 
     * Priority:
     * 1. Use location's coordinates if available and valid
     * 2. Generate realistic random coordinates
     *
     * @param User $user
     * @return array|null Array with 'latitude' and 'longitude', or null if unable to generate
     */
    private function getCoordinatesForUser(User $user): ?array
    {
        if ($user->location && $user->location->latitude != 0 && $user->location->longitude != 0) {
            $lat = (float) $user->location->latitude;
            $lng = (float) $user->location->longitude;

            if (abs($lat) <= 90 && abs($lng) <= 180) {
                return [
                    'latitude' => $lat,
                    'longitude' => $lng,
                ];
            }
        }

        return $this->generateRealisticCoordinates();
    }

    /**
     * Generate realistic random coordinates.
     * 
     * Uses ranges that cover most populated areas:
     * - Latitude: -55 to 70 (avoids extreme polar regions)
     * - Longitude: -180 to 180 (full range)
     *
     * @return array Array with 'latitude' and 'longitude'
     */
    private function generateRealisticCoordinates(): array
    {
        $latitude = mt_rand(-55000000, 70000000) / 1000000;
        $longitude = mt_rand(-180000000, 180000000) / 1000000;

        return [
            'latitude' => round($latitude, 8),
            'longitude' => round($longitude, 8),
        ];
    }
}
