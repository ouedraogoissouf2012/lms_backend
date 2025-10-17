<?php

namespace Database\Factories;

use App\Models\ForumTopic;
use App\Models\ForumCategory;
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
            'category_id' => ForumCategory::factory(),
            'user_id' => User::factory(),
            'title' => $this->faker->sentence(),
            'is_pinned' => false,
            'is_locked' => false,
            'is_resolved' => false,
        ];
    }

    /**
     * Indicate that the topic is pinned.
     */
    public function pinned(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_pinned' => true,
        ]);
    }

    /**
     * Indicate that the topic is locked.
     */
    public function locked(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_locked' => true,
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
