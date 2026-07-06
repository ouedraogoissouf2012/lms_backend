<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\SeanceRecordingStatus;
use App\Models\Chapter;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Seance;
use App\Models\SeanceRecording;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeanceRecordingTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
    }

    public function test_status_values_are_centralized_for_recording_lifecycle(): void
    {
        self::assertSame(
            ['idle', 'recording', 'uploading', 'processing', 'ready', 'failed'],
            SeanceRecordingStatus::values(),
        );

        self::assertSame(
            ['recording', 'uploading', 'processing'],
            SeanceRecordingStatus::activeValues(),
        );
    }

    public function test_recording_relations_link_seance_lesson_and_chapter(): void
    {
        $seance = Seance::factory()->forInstitution($this->institution)->create();
        $lesson = Lesson::factory()->create(['institution_id' => $this->institution->id]);
        $chapter = Chapter::factory()
            ->forLesson($lesson)
            ->forInstitution($this->institution)
            ->create();

        $recording = SeanceRecording::factory()
            ->forSeance($seance)
            ->forLesson($lesson)
            ->forChapter($chapter)
            ->ready()
            ->create();

        self::assertTrue($recording->seance->is($seance));
        self::assertTrue($recording->lesson->is($lesson));
        self::assertTrue($recording->chapter->is($chapter));
        self::assertTrue($seance->latestRecording->is($recording));
        self::assertTrue($lesson->seanceRecordings->first()->is($recording));
        self::assertTrue($chapter->seanceRecordings->first()->is($recording));
    }

    public function test_recording_payload_matches_front_contract(): void
    {
        $seance = Seance::factory()->forInstitution($this->institution)->create();
        $recording = SeanceRecording::factory()
            ->forSeance($seance)
            ->ready()
            ->create([
                'recording_url' => 'https://example.test/protected/recording.mp4',
            ]);

        self::assertSame(
            [
                'id',
                'status',
                'url',
                'started_at',
                'stopped_at',
                'processed_at',
                'error_message',
            ],
            array_keys($recording->toRecordingPayload()),
        );
        self::assertSame('ready', $recording->toRecordingPayload()['status']);
        self::assertSame('https://example.test/protected/recording.mp4', $recording->toRecordingPayload()['url']);
    }

    public function test_active_lock_prevents_two_active_recordings_for_same_seance(): void
    {
        $seance = Seance::factory()->forInstitution($this->institution)->create();

        $first = SeanceRecording::factory()
            ->forSeance($seance)
            ->recording()
            ->create();

        self::assertSame(
            SeanceRecording::activeLockKeyForSeance($seance->id),
            $first->active_lock_key,
        );

        $this->expectException(QueryException::class);

        SeanceRecording::factory()
            ->forSeance($seance)
            ->recording()
            ->create();
    }

    public function test_terminal_status_releases_active_lock_for_next_attempt(): void
    {
        $seance = Seance::factory()->forInstitution($this->institution)->create();

        $first = SeanceRecording::factory()
            ->forSeance($seance)
            ->recording()
            ->create();

        $first->update([
            'status' => SeanceRecordingStatus::Ready,
            'recording_url' => 'https://example.test/recording.mp4',
            'processed_at' => now(),
        ]);

        self::assertNull($first->refresh()->active_lock_key);

        $second = SeanceRecording::factory()
            ->forSeance($seance)
            ->recording()
            ->create();

        self::assertSame(
            SeanceRecording::activeLockKeyForSeance($seance->id),
            $second->active_lock_key,
        );
    }
}
