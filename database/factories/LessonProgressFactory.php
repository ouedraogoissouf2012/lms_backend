<?php

namespace Database\Factories;

use App\Models\LessonProgress;
use App\Models\User;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

class LessonProgressFactory extends Factory
{
    protected $model = LessonProgress::class;

    public function definition(): array
    {
        $status = $this->faker->randomElement(['not_started', 'in_progress', 'completed']);
        $progressPercentage = $status === 'completed' ? 100 :
                            ($status === 'in_progress' ? $this->faker->numberBetween(1, 99) : 0);

        return [
            'user_id' => User::factory(),
            'lesson_id' => Lesson::factory(),
            'status' => $status,
            'progress_percentage' => $progressPercentage,
            'time_spent_minutes' => $this->faker->numberBetween(0, 120),
            'started_at' => $status !== 'not_started' ? $this->faker->dateTimeBetween('-30 days', 'now') : null,
            'completed_at' => $status === 'completed' ? $this->faker->dateTimeBetween('-15 days', 'now') : null,
            'last_accessed_at' => $status !== 'not_started' ? $this->faker->dateTimeBetween('-7 days', 'now') : null,
            'notes' => $this->faker->optional()->paragraph(),
            'rating' => $status === 'completed' ? $this->faker->optional()->numberBetween(1, 5) : null,
            'feedback' => $status === 'completed' ? $this->faker->optional()->paragraph() : null,
        ];
    }

    public function notStarted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'not_started',
            'progress_percentage' => 0,
            'time_spent_minutes' => 0,
            'started_at' => null,
            'completed_at' => null,
            'last_accessed_at' => null,
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in_progress',
            'progress_percentage' => $this->faker->numberBetween(1, 99),
            'started_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'completed_at' => null,
            'last_accessed_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'progress_percentage' => 100,
            'started_at' => $this->faker->dateTimeBetween('-30 days', '-15 days'),
            'completed_at' => $this->faker->dateTimeBetween('-15 days', 'now'),
            'last_accessed_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
        ]);
    }
}
