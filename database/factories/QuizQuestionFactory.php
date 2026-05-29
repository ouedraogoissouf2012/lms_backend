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
        // Aligné avec la migration `2025_10_14_180100_create_quiz_questions_table.php` :
        // colonnes réelles = quiz_id, question_text, explanation, type, order, points,
        // is_required, metadata, institution_id. Les anciens champs `options` et
        // `correct_answer` étaient des artefacts factory non migrés en DB.
        $type = $this->faker->randomElement(['multiple_choice', 'true_false', 'short_answer']);

        return [
            'quiz_id'        => Quiz::factory(),
            'question_text'  => $this->faker->sentence() . '?',
            'type'           => $type,
            'order'          => $this->faker->numberBetween(1, 100),
            'points'         => $this->faker->randomElement([1, 2, 5, 10]),
            'is_required'    => true,
            // institution_id hérité du parent Quiz.
            'institution_id' => fn (array $attrs) => Quiz::find($attrs['quiz_id'])?->institution_id
                ?? \App\Models\Institution::factory(),
        ];
    }

    /**
     * Create a multiple choice question.
     */
    public function multipleChoice(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'multiple_choice',
        ]);
    }

    /**
     * Create a true/false question.
     */
    public function trueFalse(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'true_false',
        ]);
    }

    /**
     * Create a short answer question.
     */
    public function shortAnswer(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'short_answer',
        ]);
    }
}
