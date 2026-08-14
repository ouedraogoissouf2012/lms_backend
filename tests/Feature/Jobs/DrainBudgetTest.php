<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ArchiveOldSeances;
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
 * Issue #539 (P1/PERF) — Budget-temps de drain pour les jobs de maintenance.
 *
 * Un job long tenait le worker jusqu'à son `$timeout` (jusqu'à 600 s), retardant
 * les jobs `high` (visio) jusqu'à ~10 min. Les jobs étant idempotents, chaque run
 * traite un lot borné puis s'arrête proprement (reprise au drain suivant).
 *
 * @see app/Jobs/Concerns/InteractsWithDrainBudget.php
 * @see app/Services/Seances/Sync/KlassciSeancesSyncService.php
 */
final class DrainBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_archive_job_stops_at_budget_then_resumes_next_run(): void
    {
        $inst = Institution::factory()->create();
        app(TenantManager::class)->set($inst);

        Seance::factory()->count(3)->create([
            'institution_id' => $inst->id,
            'is_active' => true,
            'created_at' => now()->subWeeks(3),
        ]);

        // Budget 0 + lot de 1 : traite 1 séance puis s'arrête (budget dépassé).
        $job = new ArchiveOldSeances;
        $job->drainBudgetSeconds = 0;
        $job->drainChunkSize = 1;
        $this->app->call([$job, 'handle']);

        $this->assertSame(
            2,
            Seance::where('is_active', true)->count(),
            'Budget atteint : 1 archivée, 2 restantes.',
        );

        // Run suivant (budget par défaut) : termine le reste (job idempotent).
        $this->app->call([new ArchiveOldSeances, 'handle']);

        $this->assertSame(
            0,
            Seance::where('is_active', true)->count(),
            'Reprise au drain suivant : toutes les séances anciennes archivées.',
        );
    }

    public function test_sync_skips_archiving_when_pass_truncated_by_budget(): void
    {
        $inst = Institution::factory()->create();
        User::factory()->create([
            'institution_id' => $inst->id,
            'role' => 'enseignant',
            'klassci_token' => 'fake-token',
        ]);

        // Séance locale « stale » : active, avec un klassci_seance_id ABSENT des ids
        // actifs renvoyés par KLASSCI (999) → candidate à l'archivage.
        $stale = Seance::factory()->create([
            'institution_id' => $inst->id,
            'is_active' => true,
            'klassci_seance_id' => 888,
        ]);

        // KLASSCI mocké : l'enseignant a 1 matière (id 1) avec 1 séance active (id 999).
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->andReturnUsing(function (string $token, string $endpoint): array {
                    if ($endpoint === 'matieres') {
                        return ['data' => [['id' => 1]]];
                    }

                    return ['data' => []];
                });
            $mock->shouldReceive('fetchManyMatieresDetails')
                ->with([1], 'fake-token')
                ->andReturn([1 => ['data' => ['seances_programmees' => [['id' => 999]]]]]);
        });

        $service = app(KlassciSeancesSyncService::class);

        // Passe TRONQUÉE (budget 0) : l'archivage est reporté — les enseignants non
        // atteints n'ont pas alimenté la liste des ids actifs, donc on ne doit PAS
        // archiver (sinon on archiverait à tort leurs séances). La stale reste active.
        $service->sync(0);
        $this->assertTrue(
            (bool) $stale->fresh()->is_active,
            'Passe tronquée par le budget : archivage reporté, séance non touchée.',
        );

        // Passe COMPLÈTE (budget large) : l'archivage s'applique — la séance 888
        // (disparue de KLASSCI) est archivée.
        $service->sync(3600);
        $this->assertFalse(
            (bool) $stale->fresh()->is_active,
            'Passe complète : la séance absente de KLASSCI est archivée.',
        );
    }
}
