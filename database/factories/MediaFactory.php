<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        $fileName = Str::random(40) . '.txt';
        $tags = ['advert', 'memory', 'maths', 'photo', 'video', 'document', 'important', 'personal', 'work', 'family'];
        return [
            'user_id' => User::factory(),
            'name' => 'test.txt',
            'file_name' => $fileName,
            'mime_type' => 'text/plain',
            'size' => $this->faker->numberBetween(1000, 10000000),
            'path' => 'media/' . $fileName,
            'disk' => 'local',
            'metadata' => [
                'description' => $this->faker->sentence(),
                'tags' => $this->faker->randomElements($tags, rand(1, 3))
            ]
        ];
    }
} 