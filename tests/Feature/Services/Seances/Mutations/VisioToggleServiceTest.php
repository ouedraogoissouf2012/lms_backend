<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Seances\Mutations;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Seances\Mutations\VisioToggleService;
use App\Services\TenantManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #542 — `Seance::updateOrCreate(['klassci_seance_id' => $seanceId], ...)`
 * exclut implicitement les lignes soft-deletées (SoftDeletingScope non
 * retiré) : une resurrection de la même séance visio après suppression tente
 * un INSERT qui viole l'unique composite `(klassci_seance_id, institution_id)`
 * → 500 permanent.
 */
final class VisioToggleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_toggle_of_a_soft_deleted_seance_restores_it_instead_of_erroring(): void
    {
        $institution = Institution::factory()->create();
        app(TenantManager::class)->set($institution);

        $user = User::factory()->for($institution)->create(['role' => 'enseignant']);

        // Archivée AVANT sa suppression — cas réaliste, cf. équivalent job
        // dans KlassciSeancesSyncServiceTest.
        $trashed = Seance::factory()->forInstitution($institution)->create([
            'klassci_seance_id' => 4242,
            'visio_enabled' => false,
            'is_active' => false,
            'archived_at' => now()->subDay(),
            'archive_reason' => 'supprimee_klassci',
        ]);
        $trashed->delete();
        self::assertSoftDeleted('seances', ['id' => $trashed->id]);

        // enabled=false : évite dispatchNotifications() (hors périmètre de ce test).
        $result = app(VisioToggleService::class)->toggle(4242, false, null, $user);

        self::assertSame(200, $result['status'], "Le toggle d'une séance soft-deletée ne doit JAMAIS produire une 500 (violation d'unique).");
        self::assertTrue($result['payload']['success']);
        self::assertNotSoftDeleted('seances', ['id' => $trashed->id]);
        self::assertDatabaseCount('seances', 1);

        $trashed->refresh();
        self::assertTrue(
            (bool) $trashed->is_active,
            'Une séance archivée AVANT sa suppression doit être désarchivée par le toggle — sinon invisible aux étudiants malgré un 200.',
        );
        self::assertNull($trashed->archived_at);
        self::assertNull($trashed->archive_reason);
    }

    public function test_toggle_without_any_trashed_seance_keeps_nominal_create_behavior(): void
    {
        $institution = Institution::factory()->create();
        app(TenantManager::class)->set($institution);

        $user = User::factory()->for($institution)->create(['role' => 'enseignant']);

        $result = app(VisioToggleService::class)->toggle(9999, false, null, $user);

        self::assertSame(200, $result['status']);
        self::assertDatabaseHas('seances', [
            'klassci_seance_id' => 9999,
            'institution_id' => $institution->id,
        ]);
    }

    /**
     * Revue de code #542 — régression trouvée dans `VisioToggleService` :
     * `updateOrCreate()` (remplacé) s'appuie sur `Builder::createOrFirst()`
     * (vendor Laravel), qui absorbe une course concurrente sur `create()` en
     * retentant le lookup au lieu d'échouer. `restoreOrCreateVisio()`
     * reproduit ce comportement via `catch (UniqueConstraintViolationException)`
     * — jusqu'ici vérifié seulement par lecture du vendor, pas par un test
     * (limite honnête assumée dans design.md). Corrigé ici : `DB::listen()`
     * intercepte la requête SELECT du lookup (`withTrashed()->first()`,
     * déjà exécutée à ce stade et ayant retourné `null`) pour insérer, EN
     * SYNCHRONE, la ligne « concurrente » juste avant que le code ne tente
     * son propre `Seance::create()` — reproduit fidèlement la fenêtre de
     * course TOCTOU sans concurrence réelle ni seam de test dans le code de
     * production.
     */
    public function test_toggle_retries_after_concurrent_create_race(): void
    {
        $institution = Institution::factory()->create();
        app(TenantManager::class)->set($institution);
        $user = User::factory()->for($institution)->create(['role' => 'enseignant']);

        $seanceId = 7777;
        $raceInjected = false;

        DB::listen(function (QueryExecuted $event) use (&$raceInjected, $seanceId, $institution): void {
            if ($raceInjected || ! str_contains(strtolower($event->sql), 'select')
                || ! str_contains($event->sql, 'klassci_seance_id')) {
                return;
            }

            $raceInjected = true;

            // Simule une requête concurrente qui vient de créer la même
            // séance ENTRE le lookup ci-dessus (déjà exécuté, résultat
            // `null`) et le `Seance::create()` que ce test s'apprête à
            // déclencher.
            DB::table('seances')->insert(
                Seance::factory()->forInstitution($institution)->raw(['klassci_seance_id' => $seanceId]),
            );
        });

        try {
            $result = app(VisioToggleService::class)->toggle($seanceId, false, null, $user);
        } finally {
            DB::flushQueryLog();
        }

        self::assertTrue($raceInjected, 'La course simulée doit avoir été injectée pendant le test (sinon le test ne prouve rien).');
        self::assertSame(
            200,
            $result['status'],
            "Une course concurrente sur create() doit être absorbée par le retry (comme l'ancien updateOrCreate() le faisait nativement), pas remonter en 500.",
        );
        // Retry réussi : restauration/mise à jour de la ligne concurrente, pas une 2e ligne dupliquée.
        self::assertDatabaseCount('seances', 1);
    }

    public function test_toggle_never_restores_a_trashed_seance_belonging_to_another_institution(): void
    {
        $institutionA = Institution::factory()->create();
        $institutionB = Institution::factory()->create();

        $trashedInB = Seance::factory()->forInstitution($institutionB)->create([
            'klassci_seance_id' => 4242,
        ]);
        $trashedInB->delete();

        app(TenantManager::class)->set($institutionA);
        $userA = User::factory()->for($institutionA)->create(['role' => 'enseignant']);

        $result = app(VisioToggleService::class)->toggle(4242, false, null, $userA);

        self::assertSame(200, $result['status']);
        self::assertSoftDeleted('seances', ['id' => $trashedInB->id]);
        self::assertDatabaseHas('seances', [
            'klassci_seance_id' => 4242,
            'institution_id' => $institutionA->id,
            'deleted_at' => null,
        ]);
    }
}
