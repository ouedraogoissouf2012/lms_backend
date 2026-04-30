<?php

namespace Database\Factories;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

class EvaluationQuestionFactory extends Factory
{
    protected $model = EvaluationQuestion::class;

    public function definition(): array
    {
        return [
            'evaluation_id' => Evaluation::factory(),
            'question' => $this->faker->sentence(),
            'type' => $this->faker->randomElement(['multiple_choice', 'short_answer', 'essay']),
            'ordre' => $this->faker->numberBetween(1, 10),
        ];
    }
}
