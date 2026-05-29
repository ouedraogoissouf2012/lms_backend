<?php

namespace Database\Factories;

use App\Models\ForumTopic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ForumTopic>
 */
class ForumTopicFactory extends Factory
{
    protected $model = ForumTopic::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => $this->faker->sentence(),
            'content' => $this->faker->paragraph(),
            'status' => 'open',
            'is_resolved' => false,
            'views_count' => 0,
            'posts_count' => 0,
            'last_activity_at' => now(),
            // institution_id requis : ForumTopic est tenant-scoped via BelongsToInstitution.
            'institution_id' => \App\Models\Institution::factory(),
        ];
    }

    /**
     * Indicate that the topic is pinned.
     */
    public function pinned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pinned',
        ]);
    }

    /**
     * Indicate that the topic is closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
        ]);
    }

    /**
     * Indicate that the topic is resolved.
     */
    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_resolved' => true,
        ]);
    }
}
