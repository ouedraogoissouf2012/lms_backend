<?php

namespace Database\Factories;

use App\Models\Chapter;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Chapter>
 */
class ChapterFactory extends Factory
{
    protected $model = Chapter::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'matiere_id' => Matiere::factory(),
            'enseignant_id' => User::factory()->teacher(),
            'institution_id' => Institution::factory(),
            'title' => $this->faker->sentence(),
            'description' => $this->faker->paragraph(),
            'content_type' => $this->faker->randomElement(['text', 'video', 'powerpoint', 'pdf']),
            'content' => $this->faker->paragraphs(3, true),
            'video_url' => null,
            'video_provider' => null,
            'pdf_url' => null,
            'audio_url' => null,
            'presentation_url' => null,
            'external_link' => null,
            'file_original_path' => null,
            'file_converted_path' => null,
            'slides_images' => null,
            'slides_count' => null,
            'notes_enseignant' => null,
            'allow_download' => true,
            'show_slide_numbers' => true,
            'autoplay_video' => false,
            'order' => $this->faker->numberBetween(0, 100),
            'duration_minutes' => $this->faker->numberBetween(15, 120),
        ];
    }

    /**
     * Set chapter to text type.
     */
    public function textContent(): static
    {
        return $this->state(fn (array $attributes) => [
            'content_type' => 'text',
            'video_url' => null,
            'presentation_url' => null,
        ]);
    }

    /**
     * Set chapter to video type.
     */
    public function videoContent(): static
    {
        return $this->state(fn (array $attributes) => [
            'content_type' => 'video',
            'video_url' => 'https://example.com/video.mp4',
            'video_provider' => 'youtube',
        ]);
    }

    /**
     * Set chapter to PowerPoint type.
     */
    public function powerpointContent(): static
    {
        return $this->state(fn (array $attributes) => [
            'content_type' => 'powerpoint',
            'presentation_url' => 'https://example.com/presentation.pptx',
            'slides_images' => ['slide1.jpg', 'slide2.jpg'],
            'slides_count' => 2,
        ]);
    }

    /**
     * Set a specific lesson.
     */
    public function forLesson(Lesson $lesson): static
    {
        return $this->state(fn (array $attributes) => [
            'lesson_id' => $lesson->id,
        ]);
    }

    /**
     * Set a specific teacher.
     */
    public function forTeacher(User $teacher): static
    {
        return $this->state(fn (array $attributes) => [
            'enseignant_id' => $teacher->id,
        ]);
    }

    /**
     * Set a specific matière.
     */
    public function forMatiere(Matiere $matiere): static
    {
        return $this->state(fn (array $attributes) => [
            'matiere_id' => $matiere->id,
        ]);
    }

    /**
     * Set a specific institution.
     */
    public function forInstitution(Institution $institution): static
    {
        return $this->state(fn (array $attributes) => [
            'institution_id' => $institution->id,
        ]);
    }
}
