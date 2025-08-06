<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Question>
 */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $options = [
            'A' => $this->faker->text(50),
            'B' => $this->faker->text(50),
            'C' => $this->faker->text(50),
            'D' => $this->faker->text(50),
        ];

        $correctAnswer = $this->faker->randomElement(['A', 'B', 'C', 'D']);

        return [
            'question' => $this->faker->text(100),
            'answer' => $correctAnswer,
            'option_a' => $options['A'],
            'option_b' => $options['B'],
            'option_c' => $options['C'],
            'option_d' => $options['D'],
            'media_id' => Media::factory(),
        ];
    }
}
