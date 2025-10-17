<?php

namespace Database\Factories;

use App\Models\QuizQuestion;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuizQuestion>
 */
class QuizQuestionFactory extends Factory
{
    protected $model = QuizQuestion::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = $this->faker->randomElement(['multiple_choice', 'true_false', 'short_answer']);

        return [
            'quiz_id' => Quiz::factory(),
            'question_text' => $this->faker->sentence() . '?',
            'question_type' => $type,
            'options' => $this->getOptionsForType($type),
            'correct_answer' => $this->getCorrectAnswerForType($type),
            'points' => $this->faker->randomElement([1, 2, 5, 10]),
            'order' => $this->faker->numberBetween(1, 100),
        ];
    }

    /**
     * Get options based on question type.
     */
    protected function getOptionsForType(string $type): ?array
    {
        return match ($type) {
            'multiple_choice' => [
                'A' => $this->faker->sentence(),
                'B' => $this->faker->sentence(),
                'C' => $this->faker->sentence(),
                'D' => $this->faker->sentence(),
            ],
            'true_false' => [
                'true' => 'Vrai',
                'false' => 'Faux',
            ],
            default => null,
        };
    }

    /**
     * Get correct answer based on question type.
     */
    protected function getCorrectAnswerForType(string $type): string
    {
        return match ($type) {
            'multiple_choice' => $this->faker->randomElement(['A', 'B', 'C', 'D']),
            'true_false' => $this->faker->randomElement(['true', 'false']),
            default => $this->faker->sentence(),
        };
    }

    /**
     * Create a multiple choice question.
     */
    public function multipleChoice(): static
    {
        return $this->state(fn (array $attributes) => [
            'question_type' => 'multiple_choice',
            'options' => [
                'A' => $this->faker->sentence(),
                'B' => $this->faker->sentence(),
                'C' => $this->faker->sentence(),
                'D' => $this->faker->sentence(),
            ],
            'correct_answer' => $this->faker->randomElement(['A', 'B', 'C', 'D']),
        ]);
    }

    /**
     * Create a true/false question.
     */
    public function trueFalse(): static
    {
        return $this->state(fn (array $attributes) => [
            'question_type' => 'true_false',
            'options' => [
                'true' => 'Vrai',
                'false' => 'Faux',
            ],
            'correct_answer' => $this->faker->randomElement(['true', 'false']),
        ]);
    }

    /**
     * Create a short answer question.
     */
    public function shortAnswer(): static
    {
        return $this->state(fn (array $attributes) => [
            'question_type' => 'short_answer',
            'options' => null,
            'correct_answer' => $this->faker->sentence(),
        ]);
    }
}
