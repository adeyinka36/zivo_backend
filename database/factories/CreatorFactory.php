<?php

namespace Database\Factories;

use App\Models\Creator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Creator>
 */
class CreatorFactory extends Factory
{
    protected $model = Creator::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'business_name' => $this->faker->company(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'phone' => $this->faker->phoneNumber(),
            'address' => $this->faker->address(),
            'secret' => Str::random(32),
            'is_limited_company' => $this->faker->boolean(30),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
