<?php

namespace Database\Factories;

use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ForumPost>
 */
class ForumPostFactory extends Factory
{
    protected $model = ForumPost::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'topic_id' => ForumTopic::factory(),
            'user_id' => User::factory(),
            'content' => $this->faker->paragraph(),
            'parent_id' => null,
            'is_solution' => false,
            'is_edited' => false,
            'likes_count' => 0,
            // institution_id hérité du parent ForumTopic.
            'institution_id' => fn (array $attrs) => ForumTopic::find($attrs['topic_id'])?->institution_id
                ?? \App\Models\Institution::factory(),
        ];
    }

    /**
     * Set a specific topic for the post.
     */
    public function forTopic(ForumTopic $topic): static
    {
        return $this->state(fn (array $attributes) => [
            'topic_id' => $topic->id,
        ]);
    }

    /**
     * Set a specific user for the post.
     */
    public function byUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
