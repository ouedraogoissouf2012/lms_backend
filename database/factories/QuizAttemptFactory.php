<?php

namespace Database\Factories;

use App\Models\QuizAttempt;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuizAttempt>
 */
class QuizAttemptFactory extends Factory
{
    protected $model = QuizAttempt::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = $this->faker->dateTimeBetween('-1 month', 'now');
        $submittedAt = $this->faker->dateTimeBetween($startedAt, 'now');
        $pointsPossible = $this->faker->numberBetween(50, 150);

        return [
            'quiz_id' => Quiz::factory(),
            'user_id' => User::factory()->create(['role' => 'étudiant']),
            'score' => $this->faker->numberBetween(0, 100),
            'points_possible' => $pointsPossible,
            'points_earned' => $this->faker->numberBetween(0, $pointsPossible),
            'answers' => [],
            'status' => 'submitted',
            'started_at' => $startedAt,
            'submitted_at' => $submittedAt,
            'completed_at' => null,
            // institution_id hérité du parent Quiz.
            'institution_id' => fn (array $attrs) => Quiz::find($attrs['quiz_id'])?->institution_id
                ?? \App\Models\Institution::factory(),
        ];
    }

    /**
     * Indicate that the attempt is in progress.
     * CRITICAL: Sets started_at to NOW so isTimeExpired() returns false
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in_progress',
            'started_at' => now(),  // CRITICAL: time-sensitive for saveProgress endpoint
            'score' => null,
            'completed_at' => null,
        ]);
    }

    /**
     * Indicate that the attempt is graded.
     */
    public function graded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'graded',
            'completed_at' => now(),
        ]);
    }

    /**
     * Set a specific score for the attempt.
     */
    public function withScore(int $score): static
    {
        return $this->state(fn (array $attributes) => [
            'score' => $score,
        ]);
    }

    /**
     * Set a specific quiz for the attempt.
     */
    public function forQuiz(Quiz $quiz): static
    {
        return $this->state(fn (array $attributes) => [
            'quiz_id' => $quiz->id,
        ]);
    }

    /**
     * Set a specific user for the attempt.
     */
    public function byUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
