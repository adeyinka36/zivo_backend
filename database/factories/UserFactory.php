<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $location = Location::inRandomOrder()->first();
        $coordinates = $this->getCoordinatesForLocation($location);

        return [
            'name' => $this->faker->name(),
            'username' => $this->faker->unique()->userName(),
            'email' => $this->faker->unique()->safeEmail(),
            'zivos' => $this->faker->numberBetween(0, 100),
            'email_verified_at' => now(),
            'password' => static::$password ??= bcrypt('Secret19'),
            'remember_token' => Str::random(10),
            'push_notifications' => true,
            'location_id' => $location?->id ?? null,
            'latitude' => $coordinates['latitude'],
            'longitude' => $coordinates['longitude'],
        ];
    }

    /**
     * Get coordinates for a location.
     * 
     * Uses location's coordinates if available and valid,
     * otherwise generates realistic random coordinates.
     *
     * @param Location|null $location
     * @return array Array with 'latitude' and 'longitude'
     */
    private function getCoordinatesForLocation(?Location $location): array
    {
        if ($location && $location->latitude != 0 && $location->longitude != 0) {
            $lat = (float) $location->latitude;
            $lng = (float) $location->longitude;

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

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

}
