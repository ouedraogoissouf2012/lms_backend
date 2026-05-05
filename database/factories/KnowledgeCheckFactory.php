<?php

namespace Database\Factories;

use App\Models\KnowledgeCheck;
use App\Models\Chapter;
use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\KnowledgeCheck>
 */
class KnowledgeCheckFactory extends Factory
{
    protected $model = KnowledgeCheck::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chapter_id' => Chapter::factory(),
            'institution_id' => Institution::factory(),
            'title' => $this->faker->sentence(),
            'description' => $this->faker->paragraph(),
            'questions' => [
                [
                    'question' => 'What is 2 + 2?',
                    'type' => 'single',
                    'options' => ['3', '4', '5'],
                    'correct_answer' => '4',
                    'explanation' => 'The answer is 4',
                    'points' => 1,
                ],
                [
                    'question' => 'Is the sky blue?',
                    'type' => 'true_false',
                    'options' => ['True', 'False'],
                    'correct_answer' => 'True',
                    'explanation' => 'Yes, the sky is blue',
                    'points' => 1,
                ],
            ],
            'passing_score' => 70,
            'max_attempts' => 3,
            'shuffle_questions' => false,
            'shuffle_options' => false,
            'show_correct_answers' => true,
            'show_explanation' => true,
            'time_limit_minutes' => 30,
            'position' => 0,
            'is_active' => true,
            'is_required' => false,
        ];
    }

    /**
     * Set to inactive state.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Set to required state.
     */
    public function required(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_required' => true,
        ]);
    }

    /**
     * Set to unlimited attempts.
     */
    public function unlimitedAttempts(): static
    {
        return $this->state(fn (array $attributes) => [
            'max_attempts' => null,
        ]);
    }
}
