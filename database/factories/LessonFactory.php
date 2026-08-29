<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Lesson>
 */
class LessonFactory extends Factory
{
    protected $model = Lesson::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'enseignant_id' => User::factory()->create(['role' => 'enseignant']),
            'title' => $this->faker->sentence(),
            'description' => $this->faker->paragraph(),
            'content' => $this->faker->paragraphs(5, true),
            // Défaut déterministe : draft (published_at null). Un status aléatoire
            // rendait les tests non déterministes vis-à-vis du scope published()
            // (#481). Les tests voulant une leçon publiée utilisent ->published().
            'status' => 'draft',
            'published_at' => null,
            'order' => $this->faker->numberBetween(1, 100),
            'type' => 'cours',
            // institution_id requis : la Lesson est tenant-scoped via BelongsToInstitution.
            'institution_id' => \App\Models\Institution::factory(),
        ];
    }

    /**
     * Indicate that the lesson is published.
     */
    public function published(): static
    {
        // published_at cohérent ET dans le passé → visible via Lesson::published()
        // (le scope exige published_at <= now()). Invariant #481.
        return $this->state(fn (array $attributes) => [
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    /**
     * Indicate that the lesson is a draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'published_at' => null,
        ]);
    }

    /**
     * Indicate that the lesson is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'archived',
            'published_at' => null,
        ]);
    }

    /**
     * Set a specific teacher for the lesson.
     */
    public function forTeacher(User $teacher): static
    {
        return $this->state(fn (array $attributes) => [
            'enseignant_id' => $teacher->id,
        ]);
    }
}
