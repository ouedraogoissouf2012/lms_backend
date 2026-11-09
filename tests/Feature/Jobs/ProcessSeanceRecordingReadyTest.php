<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\RecordingFailureReason;
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
use App\Services\Visio\Recording\SeanceRecordingAttachmentGuard;
use App\Services\Visio\Recording\SeanceRecordingAttachmentResolver;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Monolog\Level;
use PDOException;
use Psr\Log\NullLogger;
use Tests\Concerns\CapturesWrittenLog;
use Tests\TestCase;

final class ProcessSeanceRecordingReadyTest extends TestCase
{
    use CapturesWrittenLog;
    use RefreshDatabase;

    /** Une adresse factice, liée à la requête SQL qui échoue pendant le traitement. */
    private const TEMOIN_SQL = 'temoin.traitement.948@exemple.invalid';

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
     * Une exception pendant le traitement laisse un motif du registre.
     * L'exception relancée est rapportée par le worker, et le journal écrit la
     * décrit par ExceptionDescription.
     */
    public function test_une_exception_pendant_le_traitement_laisse_une_cle_et_jamais_son_message(): void
    {
        $this->captureWrittenLog();
        [$seance] = $this->makeResolvableSeanceAndLesson();
        $recording = SeanceRecording::factory()->forSeance($seance)->processing()->create();
        Chapter::creating(static function (): never {
            throw new QueryException(
                'mysql',
                'insert into chapters (title, owner_email) values (?, ?)',
                ['Replay', self::TEMOIN_SQL],
                new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found'),
            );
        });

        try {
            $this->handle($recording, 'https://cdn.example.test/recordings/live.mp4');
            self::fail('L\'exception doit être relancée, pour que le worker la rapporte et la retente.');
        } catch (QueryException $relancee) {
            $this->app->make(ExceptionHandler::class)->report($relancee);
        }

        $recording->refresh();
        self::assertSame(SeanceRecordingStatus::Failed, $recording->status);
        self::assertSame(RecordingFailureReason::ProcessingException->value, $recording->error_message);
        self::assertCount(1, $this->recordsAtLeast(Level::Error), 'Le worker rapporte l\'exception une fois.');
        $this->assertNoWitnessWritten(self::TEMOIN_SQL);
    }

    public function test_un_echec_definitif_laisse_une_cle_et_jamais_le_message_de_l_exception(): void
    {
        [$seance] = $this->makeResolvableSeanceAndLesson();
        $recording = SeanceRecording::factory()->forSeance($seance)->processing()->create();

        (new ProcessSeanceRecordingReady($recording->id, 'https://cdn.example.test/r.mp4', null, 's3'))->failed(
            new QueryException('mysql', 'select * from users where email = ?', [self::TEMOIN_SQL], new PDOException('verrou')),
        );

        $recording->refresh();
        self::assertSame(SeanceRecordingStatus::Failed, $recording->status);
        self::assertSame(RecordingFailureReason::ProcessingException->value, $recording->error_message);
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
        return new SeanceRecordingAttachmentResolver(
            new NullLogger,
            app(SeanceRecordingAttachmentGuard::class),
        );
    }
}
