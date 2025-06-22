<?php

namespace Database\Seeders;

use App\Models\Media;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MediaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create a test user if none exists
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
            ]
        );

        // Create 5 random tags
        $tags = collect([
            'nature',
            'technology',
            'art',
            'music',
            'travel'
        ])->map(function ($name) {
            return Tag::firstOrCreate([
                'name' => $name,
                'slug' => Str::slug($name)
            ]);
        });

        // Create 100 media entries with random tags
        for ($i = 0; $i < 100; $i++) {
            $media = Media::factory()->create([
                'user_id' => $user->id
            ]);

            // Attach 1-3 random tags
            $media->tags()->attach(
                $tags->random(rand(1, 3))->pluck('id')->toArray()
            );
        }
    }
} 