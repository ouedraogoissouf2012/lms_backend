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
use Tests\TestCase;

/**
 * Issue #475 — Contrat du service de sync extrait de SyncKlassciSeances::handle().
 *
 * Vérifie que le service, testé en isolation (sans le job), retourne des stats
 * cohérentes : création d'une nouvelle séance, comptage, et archivage tenant-scopé.
 */
final class KlassciSeancesSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_sync_creates_new_seance_and_reports_stats(): void
    {
        $institution = Institution::factory()->create();
        User::factory()->for($institution)->create([
            'role' => 'enseignant',
            'name' => 'Prof',
            'klassci_id' => 1001,
            'klassci_token' => 'token-a',
        ]);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('token-a', 'matieres', 'GET')
                ->andReturn(['data' => [['id' => 10, 'nom' => 'Maths']]]);
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->with([10], 'token-a')
                ->andReturn([10 => ['data' => ['seances_programmees' => [[
                    'id' => 42,
                    'programmation' => ['date' => '2026-08-01', 'heure_debut' => '2026-08-01T09:00:00Z'],
                    'classe' => ['id' => 501, 'nom' => 'TA'],
                ]]]]]);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => ['classe' => ['id' => 501]]]);
        });

        app(TenantManager::class)->reset();
        $stats = app(KlassciSeancesSyncService::class)->sync();

        self::assertSame(1, $stats->teachersChecked);
        self::assertSame(1, $stats->seancesFound);
        self::assertSame(1, $stats->seancesNew);
        self::assertSame(0, $stats->errors);

        self::assertDatabaseHas('seances', [
            'klassci_seance_id' => 42,
            'institution_id' => $institution->id,
        ]);
    }

    public function test_sync_archives_seance_absent_from_klassci(): void
    {
        $institution = Institution::factory()->create();
        $teacher = User::factory()->for($institution)->create([
            'role' => 'enseignant',
            'klassci_id' => 1001,
            'klassci_token' => 'token-a',
        ]);

        // Séance préexistante active, absente du prochain sync → doit être archivée.
        $stale = Seance::factory()->forInstitution($institution)->create([
            'klassci_seance_id' => 77,
            'is_active' => true,
        ]);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('token-a', 'matieres', 'GET')
                ->andReturn(['data' => [['id' => 10, 'nom' => 'Maths']]]);
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->with([10], 'token-a')
                ->andReturn([10 => ['data' => ['seances_programmees' => [[
                    'id' => 42,
                    'programmation' => ['date' => '2026-08-01'],
                    'classe' => ['id' => 501, 'nom' => 'TA'],
                ]]]]]);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => ['classe' => ['id' => 501]]]);
        });

        app(TenantManager::class)->reset();
        $stats = app(KlassciSeancesSyncService::class)->sync();

        self::assertSame(1, $stats->seancesArchived);
        $stale->refresh();
        self::assertFalse($stale->is_active);
        self::assertSame('supprimee_klassci', $stale->archive_reason);
    }

    /**
     * Issue #515 — verrouille l'élimination du N+1 HTTP : les détails des
     * matières d'un enseignant doivent être récupérés en UN SEUL appel batch
     * (`fetchManyMatieresDetails`), jamais via des appels `matieres/{id}`
     * séquentiels un par un.
     */
    public function test_matiere_details_are_batch_fetched_once_per_teacher_not_sequentially(): void
    {
        $institution = Institution::factory()->create();
        User::factory()->for($institution)->create([
            'role' => 'enseignant',
            'klassci_id' => 1001,
            'klassci_token' => 'token-a',
        ]);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('token-a', 'matieres', 'GET')
                ->andReturn(['data' => [
                    ['id' => 10, 'nom' => 'Maths'],
                    ['id' => 11, 'nom' => 'Physique'],
                    ['id' => 12, 'nom' => 'Chimie'],
                ]]);

            // Le N+1 est éliminé : un seul appel batch pour les 3 matières,
            // jamais requestWithUserToken('matieres/{id}', ...).
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->once()
                ->with([10, 11, 12], 'token-a')
                ->andReturn([
                    10 => ['data' => ['seances_programmees' => []]],
                    11 => ['data' => ['seances_programmees' => []]],
                    12 => ['data' => ['seances_programmees' => []]],
                ]);

            $mock->shouldNotReceive('requestWithUserToken')
                ->with('token-a', 'matieres/10', 'GET');
            $mock->shouldNotReceive('requestWithUserToken')
                ->with('token-a', 'matieres/11', 'GET');
            $mock->shouldNotReceive('requestWithUserToken')
                ->with('token-a', 'matieres/12', 'GET');
        });

        app(TenantManager::class)->reset();
        $stats = app(KlassciSeancesSyncService::class)->sync();

        self::assertSame(1, $stats->teachersChecked);
        self::assertSame(0, $stats->errors);
    }

    /**
     * Baseline-vs-afterGrowth (#517 pattern) : le nombre d'appels batch reste
     * constant (1 par enseignant) indépendamment du nombre de matières.
     */
    public function test_batch_call_count_stays_constant_as_matiere_count_grows(): void
    {
        $institution = Institution::factory()->create();
        User::factory()->for($institution)->create([
            'role' => 'enseignant',
            'klassci_id' => 1001,
            'klassci_token' => 'token-a',
        ]);

        $manyMatieres = collect(range(1, 30))
            ->map(fn (int $i): array => ['id' => $i, 'nom' => "Matiere {$i}"])
            ->all();
        $manyDetails = collect(range(1, 30))
            ->mapWithKeys(fn (int $i): array => [$i => ['data' => ['seances_programmees' => []]]])
            ->all();

        $callCount = 0;
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($manyMatieres, $manyDetails, &$callCount): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('token-a', 'matieres', 'GET')
                ->andReturn(['data' => $manyMatieres]);
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->once()
                ->withArgs(function (array $ids) use (&$callCount): bool {
                    $callCount++;

                    return count($ids) === 30;
                })
                ->andReturn($manyDetails);
        });

        app(TenantManager::class)->reset();
        app(KlassciSeancesSyncService::class)->sync();

        self::assertSame(1, $callCount, 'fetchManyMatieresDetails doit être appelée exactement 1 fois, même à 30 matières.');
    }

    /**
     * R3/design.md — une matière omise du résultat batch (échec HTTP
     * individuel simulé) n'empêche pas la synchronisation des autres matières
     * du même enseignant (meilleur isolement de panne que l'ancien code
     * séquentiel — documenté et assumé dans design.md). L'échec reste compté
     * dans `stats->errors` : signal restauré après un finding de revue de
     * code (voir `TeacherMatieresResolver::resolve()` / `failedMatiereIds`),
     * sans quoi une matière en échec deviendrait invisible côté supervision.
     */
    public function test_matiere_omitted_from_batch_result_does_not_block_sibling_matieres(): void
    {
        $institution = Institution::factory()->create();
        User::factory()->for($institution)->create([
            'role' => 'enseignant',
            'klassci_id' => 1001,
            'klassci_token' => 'token-a',
        ]);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('token-a', 'matieres', 'GET')
                ->andReturn(['data' => [
                    ['id' => 10, 'nom' => 'Maths'],
                    ['id' => 11, 'nom' => 'Physique'],
                ]]);

            // Matière 10 en échec (omise du résultat, sémantique KlassciBatchFetcher) ;
            // matière 11 réussit et doit quand même être synchronisée.
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->once()
                ->with([10, 11], 'token-a')
                ->andReturn([
                    11 => ['data' => ['seances_programmees' => [[
                        'id' => 900,
                        'programmation' => ['date' => '2026-08-01'],
                        'classe' => ['id' => 501, 'nom' => 'TB'],
                    ]]]],
                ]);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => ['classe' => ['id' => 501]]]);
        });

        app(TenantManager::class)->reset();
        $stats = app(KlassciSeancesSyncService::class)->sync();

        self::assertSame(1, $stats->errors, 'La matière 10 (omise du batch) doit rester comptée comme erreur — signal de supervision.');
        self::assertSame(1, $stats->seancesNew, 'La matière 11 (résolue) doit quand même être synchronisée.');
        self::assertDatabaseHas('seances', ['klassci_seance_id' => 900]);
    }

    /**
     * Issue #542 — `Seance` porte un unique COMPOSITE `(klassci_seance_id,
     * institution_id)` non filtré sur `deleted_at` : une ligne soft-deletée
     * occupe toujours sa place dans l'index. Le lookup de `upsertSeance()`
     * (avant fix) exclut implicitement les lignes trashed → `createSeance()`
     * tente un INSERT qui viole l'unique → `QueryException` catchée,
     * comptée en erreur, la séance n'est JAMAIS restaurée (sync en échec
     * permanent, exactement le symptôme décrit par l'issue).
     */
    public function test_resync_of_a_soft_deleted_seance_restores_it_instead_of_erroring(): void
    {
        $institution = Institution::factory()->create();
        User::factory()->for($institution)->create([
            'role' => 'enseignant',
            'name' => 'Prof',
            'klassci_id' => 1001,
            'klassci_token' => 'token-a',
        ]);

        // Archivée (StaleSeanceArchiver) AVANT sa suppression — le cas réaliste :
        // une séance non touchée par un run de sync se fait d'abord archiver,
        // puis un enseignant/admin la supprime localement.
        $trashed = Seance::factory()->forInstitution($institution)->create([
            'klassci_seance_id' => 42,
            'is_active' => false,
            'archived_at' => now()->subDay(),
            'archive_reason' => 'supprimee_klassci',
        ]);
        $trashed->delete();
        self::assertSoftDeleted('seances', ['id' => $trashed->id]);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('token-a', 'matieres', 'GET')
                ->andReturn(['data' => [['id' => 10, 'nom' => 'Maths']]]);
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->with([10], 'token-a')
                ->andReturn([10 => ['data' => ['seances_programmees' => [[
                    'id' => 42,
                    'programmation' => ['date' => '2026-08-10', 'heure_debut' => '2026-08-10T10:00:00Z'],
                    'classe' => ['id' => 501, 'nom' => 'TA'],
                ]]]]]);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => ['classe' => ['id' => 501]]]);
        });

        app(TenantManager::class)->reset();
        $stats = app(KlassciSeancesSyncService::class)->sync();

        self::assertSame(0, $stats->errors, 'La resync d\'une séance soft-deletée ne doit JAMAIS produire une QueryException.');
        self::assertNotSoftDeleted('seances', ['id' => $trashed->id]);
        // Restauration, pas duplication — 1 seule ligne pour cette clé.
        self::assertDatabaseCount('seances', 1);

        $trashed->refresh();
        self::assertTrue(
            (bool) $trashed->is_active,
            'Une séance archivée AVANT sa suppression doit être désarchivée : la resync confirme qu\'elle existe de nouveau côté KLASSCI (sinon invisible aux étudiants malgré une resync "réussie").',
        );
        self::assertNull($trashed->archived_at);
        self::assertNull($trashed->archive_reason);
    }

    /**
     * R3 (non-régression) — cas nominal : aucune ligne trashed pour cette
     * clé, le comportement create/update doit rester identique à avant #542.
     */
    public function test_resync_without_any_trashed_seance_keeps_nominal_create_behavior(): void
    {
        $institution = Institution::factory()->create();
        User::factory()->for($institution)->create([
            'role' => 'enseignant',
            'klassci_id' => 1001,
            'klassci_token' => 'token-a',
        ]);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('token-a', 'matieres', 'GET')
                ->andReturn(['data' => [['id' => 10, 'nom' => 'Maths']]]);
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->with([10], 'token-a')
                ->andReturn([10 => ['data' => ['seances_programmees' => [[
                    'id' => 99,
                    'programmation' => ['date' => '2026-08-10'],
                    'classe' => ['id' => 501, 'nom' => 'TA'],
                ]]]]]);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => ['classe' => ['id' => 501]]]);
        });

        app(TenantManager::class)->reset();
        $stats = app(KlassciSeancesSyncService::class)->sync();

        self::assertSame(0, $stats->errors);
        self::assertSame(1, $stats->seancesNew);
        self::assertDatabaseHas('seances', ['klassci_seance_id' => 99, 'institution_id' => $institution->id]);
    }

    /**
     * R4 (isolation tenant) — une ligne trashed d'une AUTRE institution
     * portant le même `klassci_seance_id` ne doit JAMAIS être
     * restaurée/affectée par la resync d'une institution différente.
     */
    public function test_resync_never_restores_a_trashed_seance_belonging_to_another_institution(): void
    {
        $institutionA = Institution::factory()->create();
        $institutionB = Institution::factory()->create();

        User::factory()->for($institutionA)->create([
            'role' => 'enseignant',
            'klassci_id' => 1001,
            'klassci_token' => 'token-a',
        ]);

        $trashedInB = Seance::factory()->forInstitution($institutionB)->create([
            'klassci_seance_id' => 42,
            'is_active' => false,
        ]);
        $trashedInB->delete();

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('token-a', 'matieres', 'GET')
                ->andReturn(['data' => [['id' => 10, 'nom' => 'Maths']]]);
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->with([10], 'token-a')
                ->andReturn([10 => ['data' => ['seances_programmees' => [[
                    'id' => 42,
                    'programmation' => ['date' => '2026-08-10'],
                    'classe' => ['id' => 501, 'nom' => 'TA'],
                ]]]]]);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => ['classe' => ['id' => 501]]]);
        });

        app(TenantManager::class)->reset();
        $stats = app(KlassciSeancesSyncService::class)->sync();

        self::assertSame(0, $stats->errors);
        self::assertSoftDeleted('seances', ['id' => $trashedInB->id], deletedAtColumn: 'deleted_at');
        self::assertDatabaseHas('seances', [
            'klassci_seance_id' => 42,
            'institution_id' => $institutionA->id,
            'deleted_at' => null,
        ]);
    }
}
