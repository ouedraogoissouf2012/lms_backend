<?php

namespace Database\Factories;

use App\Models\Quiz;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Quiz>
 */
class QuizFactory extends Factory
{
    protected $model = Quiz::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by' => User::factory()->create(['role' => 'enseignant']),
            'title' => $this->faker->sentence(),
            'description' => $this->faker->paragraph(),
            'duration_minutes' => $this->faker->randomElement([15, 30, 45, 60, 90]),
            'passing_score' => $this->faker->numberBetween(50, 80),
            'max_attempts' => $this->faker->numberBetween(1, 3),
            // institution_id requis : Quiz est tenant-scoped via BelongsToInstitution.
            'institution_id' => \App\Models\Institution::factory(),
        ];
    }

    /**
     * Set a specific teacher for the quiz.
     */
    public function forTeacher(User $teacher): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $teacher->id,
        ]);
    }

    /**
     * Set a specific duration for the quiz.
     */
    public function withDuration(int $minutes): static
    {
        return $this->state(fn (array $attributes) => [
            'duration_minutes' => $minutes,
        ]);
    }
}
