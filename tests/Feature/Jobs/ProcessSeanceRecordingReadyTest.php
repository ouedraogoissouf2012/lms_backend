<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\SeanceRecordingStatus;
use App\Jobs\ProcessSeanceRecordingReady;
use App\Models\Chapter;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Models\User;
use App\Services\TenantManager;
use App\Services\Visio\Recording\SeanceRecordingAttachmentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\NullLogger;
use Tests\TestCase;

final class ProcessSeanceRecordingReadyTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->app->make(TenantManager::class)->set($this->institution);
    }

    public function test_ready_recording_job_attaches_video_and_marks_ready(): void
    {
        [$seance, $lesson] = $this->makeResolvableSeanceAndLesson();
        $recording = SeanceRecording::factory()->forSeance($seance)->processing()->create();

        $this->handle($recording, 'https://cdn.example.test/recordings/live.mp4', 'Replay live');

        $recording->refresh();

        self::assertSame(SeanceRecordingStatus::Ready, $recording->status);
        self::assertSame('https://cdn.example.test/recordings/live.mp4', $recording->recording_url);
        self::assertSame($lesson->id, $recording->lesson_id);
        self::assertNotNull($recording->chapter_id);
        self::assertNotNull($recording->processed_at);
        self::assertNull($recording->error_message);
        self::assertSame(1, Chapter::query()->where('content_type', 'video')->count());
    }

    public function test_attachment_failure_is_stored_on_recording(): void
    {
        $seance = Seance::factory()->forInstitution($this->institution)->create([
            'klassci_matiere_id' => null,
        ]);
        $recording = SeanceRecording::factory()->forSeance($seance)->processing()->create();

        $this->handle($recording, 'https://cdn.example.test/orphan.mp4');

        $recording->refresh();

        self::assertSame(SeanceRecordingStatus::Failed, $recording->status);
        self::assertSame('missing_klassci_matiere_id', $recording->error_message);
        self::assertSame('https://cdn.example.test/orphan.mp4', $recording->recording_url);
        self::assertNotNull($recording->processed_at);
        self::assertSame(0, Chapter::query()->where('content_type', 'video')->count());
    }

    public function test_ready_recording_job_is_idempotent(): void
    {
        [$seance] = $this->makeResolvableSeanceAndLesson();
        $recording = SeanceRecording::factory()->forSeance($seance)->processing()->create();

        $this->handle($recording, 'https://cdn.example.test/replay.mp4');
        $this->handle($recording->refresh(), 'https://cdn.example.test/replay.mp4');

        self::assertSame(SeanceRecordingStatus::Ready, $recording->refresh()->status);
        self::assertSame(1, Chapter::query()->where('content_type', 'video')->count());
    }

    /**
     * @return array{Seance, Lesson}
     */
    private function makeResolvableSeanceAndLesson(): array
    {
        $matiere = Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 701,
        ]);
        $classe = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 31,
        ]);
        $teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
            'klassci_enseignant_id' => 9001,
        ]);
        $lesson = Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'matiere_id' => $matiere->id,
            'classe_id' => $classe->id,
            'enseignant_id' => $teacher->id,
        ]);
        $seance = Seance::factory()->forInstitution($this->institution)->create([
            'klassci_matiere_id' => 701,
            'klassci_classe_id' => 31,
            'klassci_enseignant_id' => 9001,
            'klassci_seance_id' => 501,
            'titre' => 'Physique live',
        ]);

        return [$seance, $lesson];
    }

    private function handle(
        SeanceRecording $recording,
        string $url,
        ?string $title = null,
    ): void {
        $job = new ProcessSeanceRecordingReady($recording->id, $url, $title, 's3');
        $job->handle($this->resolver(), new NullLogger);
    }

    private function resolver(): SeanceRecordingAttachmentResolver
    {
        return new SeanceRecordingAttachmentResolver(new NullLogger);
    }
}
