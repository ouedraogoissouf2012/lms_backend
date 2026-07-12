<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SeanceRecordingStatus;
use App\Models\Chapter;
use App\Models\Lesson;
use App\Models\Seance;
use App\Models\SeanceRecording;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeanceRecording>
 */
final class SeanceRecordingFactory extends Factory
{
    protected $model = SeanceRecording::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seance_id' => Seance::factory(),
            'lesson_id' => null,
            'chapter_id' => null,
            'provider' => 'external',
            'provider_recording_id' => $this->faker->uuid(),
            'status' => SeanceRecordingStatus::Idle,
            'recording_url' => null,
            'title' => null,
            'file_size_bytes' => null,
            'checksum' => null,
            'error_message' => null,
            'started_at' => null,
            'stopped_at' => null,
            'processed_at' => null,
        ];
    }

    public function forSeance(Seance $seance): static
    {
        return $this->state(fn (array $attributes) => [
            'seance_id' => $seance->id,
        ]);
    }

    public function forLesson(Lesson $lesson): static
    {
        return $this->state(fn (array $attributes) => [
            'lesson_id' => $lesson->id,
        ]);
    }

    public function forChapter(Chapter $chapter): static
    {
        return $this->state(fn (array $attributes) => [
            'chapter_id' => $chapter->id,
        ]);
    }

    public function recording(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeanceRecordingStatus::Recording,
            'started_at' => now(),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeanceRecordingStatus::Processing,
            'started_at' => now()->subHour(),
            'stopped_at' => now()->subMinutes(5),
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeanceRecordingStatus::Ready,
            'recording_url' => 'https://cdn.example.test/recording.mp4',
            'processed_at' => now(),
        ]);
    }
}
