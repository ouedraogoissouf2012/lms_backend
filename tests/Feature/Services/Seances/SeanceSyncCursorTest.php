<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Seances;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\Sync\KlassciSeancesSyncService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Issue #582 (P1/PERF) — Famine de la synchronisation des séances.
 *
 * Avant correctif, `KlassciSeancesSyncService::sync()` repartait TOUJOURS du
 * premier enseignant et s'arrêtait au budget de drain (#539, 45 s). Passé le
 * volume qui tient dans ce budget, les enseignants suivants n'étaient jamais
 * atteints — et l'archivage, conditionné à une passe globale complète, ne
 * s'exécutait donc plus jamais.
 *
 * @see .claude/specs/582-seance-sync-cursor/design.md
 */
final class SeanceSyncCursorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    /**
     * R1 — Cœur de la famine : avec un budget qui ne laisse passer qu'un
     * enseignant par passe, deux passes successives doivent traiter des
     * enseignants DIFFÉRENTS. Avant correctif, les deux passes traitaient le
     * même premier enseignant, indéfiniment.
     */
    public function test_two_budget_bound_passes_process_disjoint_teachers(): void
    {
        $institution = Institution::factory()->create();
        $this->createTeachers($institution, ['token-1', 'token-2', 'token-3']);

        $seen = $this->recordKlassciCalls();
        $service = app(KlassciSeancesSyncService::class);

        $service->sync(0);
        $firstPass = $seen->tokens();

        $seen->clear();
        $service->sync(0);
        $secondPass = $seen->tokens();

        self::assertSame(['token-1'], $firstPass, 'La 1re passe doit traiter le 1er enseignant.');
        self::assertSame(
            ['token-2'],
            $secondPass,
            'La 2e passe doit REPRENDRE après le curseur, pas repartir du 1er enseignant (famine #582).',
        );
    }

    /**
     * R1 — Une fois la population épuisée, le curseur revient au début : la
     * synchronisation est cyclique, pas un parcours à sens unique qui
     * s'arrêterait au dernier enseignant.
     */
    public function test_cursor_wraps_to_the_first_teacher_once_the_list_is_exhausted(): void
    {
        $institution = Institution::factory()->create();
        $this->createTeachers($institution, ['token-1', 'token-2']);

        $seen = $this->recordKlassciCalls();
        $service = app(KlassciSeancesSyncService::class);

        $passes = [];
        foreach (range(1, 3) as $ignored) {
            $seen->clear();
            $service->sync(0);
            $passes[] = $seen->tokens();
        }

        self::assertSame(
            [['token-1'], ['token-2'], ['token-1']],
            $passes,
            'Le curseur doit boucler : après le dernier enseignant, la passe suivante repart du premier.',
        );
    }

    /**
     * R4 — L'archivage d'un tenant doit s'exécuter dès que CE tenant est
     * intégralement parcouru dans le cycle courant, sans attendre une passe
     * globale complète (qui n'arrive jamais au-delà d'un certain volume).
     */
    public function test_archives_a_tenant_as_soon_as_it_is_complete_without_waiting_for_others(): void
    {
        [$institutionA, $institutionB] = [Institution::factory()->create(), Institution::factory()->create()];
        $this->createTeachers($institutionA, ['token-a']);
        // B porte DEUX enseignants : il reste donc en cours au moment où A est
        // clos, ce qui est exactement la situation à démontrer.
        $this->createTeachers($institutionB, ['token-b1', 'token-b2']);

        $staleA = $this->staleSeance($institutionA, 100);
        $staleB = $this->staleSeance($institutionB, 200);

        $this->recordKlassciCalls();
        $service = app(KlassciSeancesSyncService::class);

        // Passe 1 : seul l'enseignant de A est traité. A n'est pas encore
        // « terminé » du point de vue du cycle — rien ne doit être archivé.
        $service->sync(0);
        self::assertTrue((bool) $staleA->fresh()->is_active, 'Tenant A pas encore clos : aucun archivage.');
        self::assertTrue((bool) $staleB->fresh()->is_active);

        // Passe 2 : on franchit la frontière A → B. A est donc complet pour ce
        // cycle et doit être archivé IMMÉDIATEMENT, alors que B est en cours.
        $service->sync(0);
        self::assertFalse(
            (bool) $staleA->fresh()->is_active,
            'Tenant A complet : ses séances disparues de KLASSCI doivent être archivées sans attendre B.',
        );
        self::assertTrue((bool) $staleB->fresh()->is_active, 'Tenant B pas encore clos.');

        // Passe 3 : dernier enseignant de B → fin de flux, B est clos à son tour.
        $service->sync(0);
        self::assertFalse((bool) $staleB->fresh()->is_active, 'Fin de flux : le dernier tenant est clos.');
    }

    /**
     * R5 — Garde-fou : corriger la famine ACTIVE un archivage jusqu'ici inerte.
     * Si l'appel KLASSCI d'un enseignant échoue, ses séances n'ont pas pu être
     * confirmées ; archiver le tenant les supprimerait en masse. On doit y
     * renoncer pour ce cycle, puis archiver normalement au cycle suivant.
     */
    public function test_does_not_archive_a_tenant_whose_teacher_failed_during_the_cycle(): void
    {
        $institution = Institution::factory()->create();
        $this->createTeachers($institution, ['token-a']);
        $stale = $this->staleSeance($institution, 100);

        $failing = true;
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use (&$failing): void {
            $mock->shouldReceive('requestWithUserToken')
                ->andReturnUsing(function (string $token, string $endpoint) use (&$failing): array {
                    if ($endpoint === 'matieres' && $failing) {
                        throw new \RuntimeException('KLASSCI indisponible');
                    }

                    return $endpoint === 'matieres'
                        ? ['data' => [['id' => 10, 'nom' => 'Maths']]]
                        : ['data' => []];
                });
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->andReturn([10 => ['data' => ['seances_programmees' => []]]]);
        });

        app(TenantManager::class)->reset();
        $service = app(KlassciSeancesSyncService::class);

        $stats = $service->sync(3600);
        self::assertSame(1, $stats->errors);
        self::assertTrue(
            (bool) $stale->fresh()->is_active,
            'Tenant souillé par une erreur : archivage renoncé pour ce cycle (sinon suppression en masse).',
        );
        self::assertSame(
            1,
            $stats->tenantsArchiveSkipped,
            'Le renoncement doit être COMPTÉ : un compteur durablement non nul signale une panne KLASSCI persistante.',
        );
        self::assertSame(0, $stats->tenantsCompleted);

        // Cycle suivant, KLASSCI répond de nouveau : le tenant est propre et
        // la séance réellement disparue est archivée.
        $failing = false;
        $service->sync(3600);
        self::assertFalse(
            (bool) $stale->fresh()->is_active,
            'Cycle propre : la séance absente de KLASSCI est archivée.',
        );
    }

    /**
     * R1 — La famine par la porte de derrière : si le curseur n'avançait qu'en
     * cas de succès, un enseignant dont l'appel KLASSCI échoue durablement
     * (jeton révoqué côté KLASSCI, compte supprimé) rebloquerait le parcours sur
     * lui-même — exactement le symptôme que cette issue corrige.
     */
    public function test_a_permanently_failing_teacher_does_not_block_the_cursor(): void
    {
        $institution = Institution::factory()->create();
        $this->createTeachers($institution, ['token-broken', 'token-healthy']);

        $seen = [];
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use (&$seen): void {
            $mock->shouldReceive('requestWithUserToken')
                ->andReturnUsing(function (string $token, string $endpoint) use (&$seen): array {
                    if ($endpoint !== 'matieres') {
                        return ['data' => []];
                    }
                    $seen[] = $token;
                    if ($token === 'token-broken') {
                        throw new \RuntimeException('Jeton KLASSCI rejeté');
                    }

                    return ['data' => [['id' => 10, 'nom' => 'Maths']]];
                });
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->andReturn([10 => ['data' => ['seances_programmees' => []]]]);
        });
        app(TenantManager::class)->reset();

        $service = app(KlassciSeancesSyncService::class);
        $service->sync(0);
        $service->sync(0);

        self::assertSame(
            ['token-broken', 'token-healthy'],
            $seen,
            'Le curseur doit dépasser un enseignant en échec, sinon la famine revient par la porte de derrière.',
        );
    }

    /**
     * R4 — Contrepartie indispensable de l'archivage par marquage : à la
     * clôture d'un tenant, une séance CONFIRMÉE durant le cycle doit survivre.
     *
     * Le piège est réel : `synced_at` et `cycle_started_at` sont des colonnes
     * `timestamp` (précision seconde), et une passe rapide confirme dans la même
     * seconde que le début du cycle. Une comparaison mal orientée archiverait
     * donc tout ce qui vient d'être synchronisé.
     */
    public function test_a_seance_confirmed_during_the_cycle_survives_the_tenant_closing(): void
    {
        $institution = Institution::factory()->create();
        $this->createTeachers($institution, ['token-a']);

        $confirmed = $this->staleSeance($institution, 42);
        $disappeared = $this->staleSeance($institution, 100);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->andReturnUsing(fn (string $token, string $endpoint): array => $endpoint === 'matieres'
                    ? ['data' => [['id' => 10, 'nom' => 'Maths']]]
                    : ['data' => []]);
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->andReturn([10 => ['data' => ['seances_programmees' => [[
                    'id' => 42,
                    'programmation' => ['date' => '2026-09-01'],
                    'classe' => ['id' => 501, 'nom' => 'TA'],
                ]]]]]);
        });
        app(TenantManager::class)->reset();

        $stats = app(KlassciSeancesSyncService::class)->sync(3600);

        self::assertTrue(
            (bool) $confirmed->fresh()->is_active,
            'La séance renvoyée par KLASSCI a été confirmée dans ce cycle : elle ne doit pas être archivée.',
        );
        self::assertNotNull($confirmed->fresh()->synced_at, 'La confirmation doit être marquée sur la ligne.');
        self::assertFalse((bool) $disappeared->fresh()->is_active, 'La séance absente de KLASSCI est archivée.');
        self::assertSame(1, $stats->seancesArchived);
    }

    /**
     * R6 — Sans métrique de passe, la famine est indétectable : c'est
     * précisément pourquoi elle n'a pas été vue. La position du curseur et les
     * compteurs de tenants doivent être journalisés à chaque passe.
     */
    public function test_logs_pass_metrics_with_cursor_position_and_tenant_counters(): void
    {
        $institution = Institution::factory()->create();
        $teachers = $this->createTeachers($institution, ['token-1', 'token-2']);

        $this->recordKlassciCalls();
        $logger = $this->spyLogger();

        app(KlassciSeancesSyncService::class)->sync(0);

        $metrics = $logger->contextOf('[SyncKlassciSeances] Passe terminée');
        self::assertNotNull($metrics, 'Chaque passe doit journaliser ses métriques.');
        self::assertSame(1, $metrics['teachers_processed']);
        self::assertSame($institution->id, $metrics['cursor_institution_id']);
        self::assertSame($teachers[0]->id, $metrics['cursor_user_id']);
        self::assertSame(0, $metrics['tenants_completed']);
        self::assertSame(0, $metrics['tenants_archive_skipped']);
        self::assertFalse($metrics['cycle_completed']);
    }

    /**
     * R7 — Non-régression : sans contrainte de budget, une passe unique couvre
     * toute la population et clôt le cycle (comportement historique attendu).
     */
    public function test_a_single_unbounded_pass_covers_every_teacher_and_completes_the_cycle(): void
    {
        $institution = Institution::factory()->create();
        $this->createTeachers($institution, ['token-1', 'token-2', 'token-3']);

        $seen = $this->recordKlassciCalls();
        $logger = $this->spyLogger();

        $stats = app(KlassciSeancesSyncService::class)->sync(3600);

        self::assertSame(['token-1', 'token-2', 'token-3'], $seen->tokens());
        self::assertSame(3, $stats->teachersChecked);

        $metrics = $logger->contextOf('[SyncKlassciSeances] Passe terminée');
        self::assertNotNull($metrics);
        self::assertTrue($metrics['cycle_completed']);
        self::assertSame(1, $metrics['tenants_completed']);
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<int, User>
     */
    private function createTeachers(Institution $institution, array $tokens): array
    {
        $created = [];
        foreach ($tokens as $index => $token) {
            $created[] = User::factory()->for($institution)->create([
                'role' => 'enseignant',
                'klassci_id' => 1000 + $index,
                'klassci_token' => $token,
            ]);
        }

        return $created;
    }

    /**
     * Séance locale active dont l'identifiant KLASSCI n'est jamais renvoyé par
     * le backend : candidate à l'archivage dès que son tenant est complet.
     */
    private function staleSeance(Institution $institution, int $klassciSeanceId): Seance
    {
        return Seance::factory()->forInstitution($institution)->create([
            'klassci_seance_id' => $klassciSeanceId,
            'is_active' => true,
        ]);
    }

    /**
     * Mock KLASSCI nominal qui enregistre l'ordre des jetons interrogés — donc
     * l'ordre réel de parcours des enseignants.
     */
    private function recordKlassciCalls(): object
    {
        $recorder = new class
        {
            /** @var array<int, string> */
            public array $seen = [];

            /** @return array<int, string> */
            public function tokens(): array
            {
                return $this->seen;
            }

            public function clear(): void
            {
                $this->seen = [];
            }
        };

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($recorder): void {
            $mock->shouldReceive('requestWithUserToken')
                ->andReturnUsing(function (string $token, string $endpoint) use ($recorder): array {
                    if ($endpoint === 'matieres') {
                        $recorder->seen[] = $token;

                        return ['data' => [['id' => 10, 'nom' => 'Maths']]];
                    }

                    return ['data' => []];
                });
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->andReturn([10 => ['data' => ['seances_programmees' => []]]]);
        });

        app(TenantManager::class)->reset();

        return $recorder;
    }

    /**
     * Journal de test : `instance()` retire l'alias `Psr\Log\LoggerInterface`
     * → `log`, donc l'injection par constructeur des services reçoit bien ce
     * double, sans perturber la façade `Log` utilisée ailleurs.
     */
    private function spyLogger(): object
    {
        $spy = new class extends AbstractLogger
        {
            /** @var array<int, array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            /**
             * @param  mixed  $level
             * @param  string|\Stringable  $message
             * @param  array<string, mixed>  $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }

            /** @return array<string, mixed>|null */
            public function contextOf(string $message): ?array
            {
                foreach ($this->records as $record) {
                    if ($record['message'] === $message) {
                        return $record['context'];
                    }
                }

                return null;
            }
        };

        $this->app->instance(LoggerInterface::class, $spy);

        return $spy;
    }
}
