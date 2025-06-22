<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create the predefined tags
        $tags = ['advert', 'memory', 'maths', 'video', 'image', 'document'];
        foreach ($tags as $tagName) {
            Tag::create([
                'name' => $tagName,
                'slug' => \Illuminate\Support\Str::slug($tagName)
            ]);
        }

        // Run the seeders in order
        $this->call([
            UserSeeder::class,
            MediaSeeder::class,
            QuestionSeeder::class,
        ]);
    }
}
