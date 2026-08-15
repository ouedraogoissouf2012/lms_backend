<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Seances\Mutations;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Seances\Mutations\VisioToggleService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
