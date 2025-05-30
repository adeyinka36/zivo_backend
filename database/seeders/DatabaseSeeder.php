<?php

namespace Database\Seeders;

use App\Models\Media;
use App\Models\Tag;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create the predefined tags
        $tags = ['advert', 'memory', 'maths'];
        $createdTags = collect();
        foreach ($tags as $tagName) {
            $createdTags->push(Tag::create([
                'name' => $tagName,
                'slug' => \Illuminate\Support\Str::slug($tagName)
            ]));
        }

        // Create a test user
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password')
        ]);

        // Create some media items with tags
        Media::factory(20)->create([
            'user_id' => $user->id
        ])->each(function ($media) use ($createdTags) {
            // Attach 1-3 random tags to each media item
            $media->tags()->attach(
                $createdTags->random(rand(1, 3))->pluck('id')->toArray()
            );
        });
    }
}
