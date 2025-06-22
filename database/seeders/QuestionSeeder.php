<?php

namespace Database\Seeders;

use App\Models\Media;
use App\Models\Question;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class QuestionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all media that don't have questions
        $mediaWithoutQuestions = Media::whereDoesntHave('questions')->get();

        foreach ($mediaWithoutQuestions as $media) {
            // Create 3-5 questions for each media
            Question::factory()
                ->count(rand(3, 5))
                ->create([
                    'media_id' => $media->id
                ]);
        }
    }
}
