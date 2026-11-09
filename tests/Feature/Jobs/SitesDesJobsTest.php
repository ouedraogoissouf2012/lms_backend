<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ArchiveOldSeances;
use App\Jobs\DispatchLessonPublishedNotifications;
use App\Jobs\SyncKlassciSeances;
use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\Seance;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Monolog\Level;
use Monolog\LogRecord;
use PDOException;
use Tests\Concerns\CapturesWrittenLog;
use Tests\TestCase;

/**
 * Les sites `handle()` des jobs et la commande de rappel journalisent leur
 * exception attrapée par `CaughtExceptionLog` : même message, même niveau, même
 * contexte métier, et la relance quand il y en avait une.
 *
 * L'échec est réel : une requête échoue (`beforeExecuting`), ou KLASSCI lève,
 * sous le site, sans double du job.
 */
final class SitesDesJobsTest extends TestCase
{
    use CapturesWrittenLog;
    use RefreshDatabase;

    private const TEMOIN = 'temoin-valeur-liee-sites';

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureWrittenLog();
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_l_archivage_journalise_en_error_puis_relance(): void
    {
        $ecole = Institution::factory()->create();
        app(TenantManager::class)->set($ecole);
        Seance::factory()->create(['institution_id' => $ecole->id, 'date_seance' => now()->subWeeks(3)]);
        $this->faireEchouerLaPremiereRequeteSur('seances');

        $this->expectException(QueryException::class);
        try {
            $this->app->call([new ArchiveOldSeances, 'handle']);
        } finally {
            $this->assertUnSiteEcrit(Level::Error, '❌ Erreur lors de l\'archivage automatique', []);
        }
    }

    public function test_la_synchronisation_des_seances_journalise_en_error_puis_relance(): void
    {
        // La synchronisation lit d'abord les enseignants (`teacherStream`), dans son try.
        $this->faireEchouerLaPremiereRequeteSur('users');

        $this->expectException(QueryException::class);
        try {
            $this->app->call([new SyncKlassciSeances, 'handle']);
        } finally {
            $this->assertUnSiteEcrit(Level::Error, 'Erreur fatale dans job SyncKlassciSeances', []);
        }
    }

    public function test_le_fan_out_des_publications_journalise_en_warning_sans_relancer(): void
    {
        $ecole = Institution::factory()->create();
        $this->klassciEchoue();

        $this->app->call([new DispatchLessonPublishedNotifications(77, 500, 'Leçon', (int) $ecole->id), 'handle']);

        $this->assertUnSiteEcrit(Level::Warning, 'Erreur lors du fan-out des notifications de publication', ['lesson_id' => 77]);
    }

    public function test_le_rappel_d_evaluation_journalise_l_evaluation_et_continue(): void
    {
        $ecole = Institution::factory()->create(['klassci_api_token' => 'jeton-institution']);
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $ecole->id,
            'is_published' => true,
            'status' => 'planifiee',
            'date_evaluation' => now()->addHours(2),
        ]);
        $this->klassciEchoue();

        $this->artisan('evaluations:notify-upcoming', ['--hours' => 24])
            ->expectsOutputToContain("Erreur pendant le traitement de l'évaluation {$evaluation->id}.")
            ->assertSuccessful();

        $this->assertUnSiteEcrit(Level::Error, 'Rappel d\'évaluation interrompu', ['evaluation_id' => $evaluation->id]);
    }

    /** @param  array<string, mixed>  $contexte */
    private function assertUnSiteEcrit(Level $niveau, string $message, array $contexte): void
    {
        $ecrits = array_values(array_filter(
            $this->recordsAtLeast(Level::Debug),
            static fn (LogRecord $r): bool => $r->message === $message,
        ));
        self::assertCount(1, $ecrits, "« {$message} » écrit une fois.");
        self::assertSame($niveau, $ecrits[0]->level);
        self::assertSame(QueryException::class, $ecrits[0]->context['exception_class'] ?? null);
        self::assertArrayHasKey('sql', $ecrits[0]->context);
        self::assertSame($niveau === Level::Error, array_key_exists('trace', $ecrits[0]->context), 'La trace va avec error, pas avec warning.');
        foreach ($contexte as $cle => $valeur) {
            self::assertSame($valeur, $ecrits[0]->context[$cle] ?? null, "Contexte métier : {$cle}");
        }
        $this->assertNoWitnessWritten(self::TEMOIN);
    }

    private function faireEchouerLaPremiereRequeteSur(string $table): void
    {
        $dejaLevee = false;
        DB::connection()->beforeExecuting(static function (string $sql, array $liaisons) use ($table, &$dejaLevee): void {
            if ($dejaLevee || ! str_contains($sql, $table)) {
                return;
            }
            $dejaLevee = true;

            throw new QueryException('sqlite', $sql, [self::TEMOIN, ...$liaisons], new PDOException('verrou'));
        });
    }

    private function klassciEchoue(): void
    {
        $echec = new QueryException('mysql', 'select * from articles where code = ?', [self::TEMOIN], new PDOException('verrou'));
        $this->mock(KlassciProxyService::class, static function (MockInterface $mock) use ($echec): void {
            $mock->shouldReceive('get', 'fetchManyByEndpoint', 'getClasseEtudiants', 'requestWithUserToken')->andThrow($echec);
        });
    }
}
