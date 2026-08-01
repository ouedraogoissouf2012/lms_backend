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
            $mock->shouldReceive('requestWithUserToken')
                ->with('token-a', 'matieres/10', 'GET')
                ->andReturn(['data' => ['seances_programmees' => [[
                    'id' => 42,
                    'programmation' => ['date' => '2026-08-01', 'heure_debut' => '2026-08-01T09:00:00Z'],
                    'classe' => ['id' => 501, 'nom' => 'TA'],
                ]]]]);
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
            $mock->shouldReceive('requestWithUserToken')
                ->with('token-a', 'matieres/10', 'GET')
                ->andReturn(['data' => ['seances_programmees' => [[
                    'id' => 42,
                    'programmation' => ['date' => '2026-08-01'],
                    'classe' => ['id' => 501, 'nom' => 'TA'],
                ]]]]);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => ['classe' => ['id' => 501]]]);
        });

        app(TenantManager::class)->reset();
        $stats = app(KlassciSeancesSyncService::class)->sync();

        self::assertSame(1, $stats->seancesArchived);
        $stale->refresh();
        self::assertFalse($stale->is_active);
        self::assertSame('supprimee_klassci', $stale->archive_reason);
    }
}
