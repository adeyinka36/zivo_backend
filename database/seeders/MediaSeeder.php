<?php

namespace Database\Seeders;

use App\Models\Creator;
use App\Models\Media;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MediaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Creates a test creator and 50 media entries with reliable sample URLs.
     */
    public function run(): void
    {
        $creator = Creator::firstOrCreate(
            ['email' => 'creator@example.com'],
            [
                'name' => 'Test Creator',
                'business_name' => 'Test Media Co',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'secret' => Str::random(32),
            ]
        );

        Media::factory()->count(50)->create([
            'creator_id' => $creator->id,
        ]);
    }
} 