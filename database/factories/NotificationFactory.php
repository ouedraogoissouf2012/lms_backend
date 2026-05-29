<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        $types = [
            Notification::TYPE_LESSON_PUBLISHED,
            Notification::TYPE_FORUM_REPLY,
            Notification::TYPE_QUIZ_AVAILABLE,
            Notification::TYPE_GRADE_RECEIVED,
            Notification::TYPE_LESSON_UPDATED,
        ];

        return [
            'user_id' => User::factory(),
            'type' => $this->faker->randomElement($types),
            'title' => $this->faker->sentence(),
            'message' => $this->faker->paragraph(),
            'data' => [
                'lesson_id' => $this->faker->numberBetween(1, 100),
            ],
            'read_at' => null,
            // institution_id requis : Notification est tenant-scoped via BelongsToInstitution.
            'institution_id' => \App\Models\Institution::factory(),
        ];
    }

    public function read(): self
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => now(),
        ]);
    }

    public function unread(): self
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => null,
        ]);
    }

    public function ofType(string $type): self
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
        ]);
    }
}
