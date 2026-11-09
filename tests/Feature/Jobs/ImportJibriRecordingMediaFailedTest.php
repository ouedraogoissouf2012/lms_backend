<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\SeanceRecordingStatus;
use App\Jobs\ImportJibriRecordingMedia;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Services\TenantManager;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Monolog\Level;
use PDOException;
use RuntimeException;
use Tests\Concerns\CapturesWrittenLog;
use Tests\TestCase;

/**
 * #708 — un crash de stockage laisse l'enregistrement Failed avec motif.
 */
final class ImportJibriRecordingMediaFailedTest extends TestCase
{
    use CapturesWrittenLog;
    use RefreshDatabase;

    private const TEMOIN_SQL = 'temoin.import.948@exemple.invalid';

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_failed_marks_recording_failed_with_stable_reason(): void
    {
        $institution = Institution::factory()->create();
        app(TenantManager::class)->set($institution);
        $seance = Seance::factory()->forInstitution($institution)->create();
        $recording = SeanceRecording::factory()->forSeance($seance)->create([
            'status' => SeanceRecordingStatus::Processing,
        ]);

        $job = new ImportJibriRecordingMedia($recording->id, 'session-708');
        $job->failed(new RuntimeException('disk full'));

        $fresh = $recording->fresh();
        self::assertSame(SeanceRecordingStatus::Failed, $fresh->status);
        self::assertSame('storage_exception', $fresh->error_message);
    }

    /**
     * Le journal de l'échec définitif décrit l'exception sans son message : celui
     * d'une exception SQL, même enveloppée, porte les valeurs de la requête.
     */
    public function test_l_echec_definitif_est_journalise_sans_les_valeurs_de_la_requete(): void
    {
        $this->captureWrittenLog();
        $institution = Institution::factory()->create();
        app(TenantManager::class)->set($institution);
        $recording = SeanceRecording::factory()->forSeance(Seance::factory()->forInstitution($institution)->create())->create([
            'status' => SeanceRecordingStatus::Processing,
        ]);
        $sql = new QueryException('mysql', 'update seance_recordings set note = ? where id = ?', [self::TEMOIN_SQL, 1], new PDOException('verrou'));

        (new ImportJibriRecordingMedia($recording->id, 'session-948'))->failed(new DeadlockException($sql->getMessage(), 0, $sql));

        $echec = $this->recordsAtLeast(Level::Error);
        self::assertCount(1, $echec);
        self::assertSame(DeadlockException::class, $echec[0]->context['exception_class'] ?? null);
        self::assertSame('update seance_recordings set note = ? where id = ?', $echec[0]->context['sql'] ?? null);
        $this->assertNoWitnessWritten(self::TEMOIN_SQL);
    }
}
