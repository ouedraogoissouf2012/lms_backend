<?php

namespace Database\Factories;

use App\Enums\SeanceRecordingStatus;
use App\Models\Chapter;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Seance;
use App\Models\SeanceRecording;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SeanceRecording>
 */
class SeanceRecordingFactory extends Factory
{
    protected $model = SeanceRecording::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'institution_id' => Institution::factory(),
            'seance_id' => Seance::factory(),
            'lesson_id' => null,
            'chapter_id' => null,
            'provider' => 'jitsi',
            'external_recording_id' => null,
            'status' => SeanceRecordingStatus::Idle,
            'recording_url' => null,
            'storage_disk' => null,
            'storage_path' => null,
            'duration_seconds' => null,
            'size_bytes' => null,
            'error_message' => null,
            'metadata' => [],
            'started_by' => null,
            'stopped_by' => null,
            'started_at' => null,
            'stopped_at' => null,
            'processed_at' => null,
            'consent_policy_version' => null,
            'expires_at' => null,
        ];
    }

    public function forSeance(Seance $seance): static
    {
        return $this->state(fn (array $attributes) => [
            'seance_id' => $seance->id,
            'institution_id' => $seance->institution_id,
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
            'started_at' => now()->subMinutes(45),
            'stopped_at' => now()->subMinutes(5),
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeanceRecordingStatus::Ready,
            'recording_url' => 'https://example.test/recordings/'.Str::uuid().'.mp4',
            'storage_disk' => 's3',
            'storage_path' => 'recordings/'.Str::uuid().'.mp4',
            'duration_seconds' => 3600,
            'size_bytes' => 250_000_000,
            'started_at' => now()->subHours(2),
            'stopped_at' => now()->subHour(),
            'processed_at' => now()->subMinutes(15),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeanceRecordingStatus::Failed,
            'error_message' => 'Provider processing failed.',
            'started_at' => now()->subHour(),
            'stopped_at' => now()->subMinutes(30),
            'processed_at' => now()->subMinutes(5),
        ]);
    }
}
