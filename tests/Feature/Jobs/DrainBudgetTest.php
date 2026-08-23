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

    /**
     * #539 + #582 — le budget de drain interrompt toujours la passe, mais la
     * conséquence sur l'archivage s'est AFFINÉE : ce n'est plus « aucune passe
     * complète, donc aucun archivage » (règle qui, la famine aidant, gelait
     * l'archivage pour toujours) mais « un tenant non entièrement parcouru
     * n'est pas archivé ». Le tenant porte donc ici DEUX enseignants : la passe
     * tronquée le laisse en cours, et rien ne doit être archivé tant qu'il
     * n'est pas clos.
     */
    public function test_sync_does_not_archive_a_tenant_left_incomplete_by_the_budget(): void
    {
        $inst = Institution::factory()->create();
        User::factory()->create([
            'institution_id' => $inst->id,
            'role' => 'enseignant',
            'klassci_token' => 'fake-token',
        ]);
        User::factory()->create([
            'institution_id' => $inst->id,
            'role' => 'enseignant',
            'klassci_token' => 'fake-token-2',
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
                ->andReturn([1 => ['data' => ['seances_programmees' => [['id' => 999]]]]]);
        });

        $service = app(KlassciSeancesSyncService::class);

        // Passe TRONQUÉE (budget 0) : un seul des deux enseignants est traité, le
        // tenant reste en cours. Archiver maintenant supprimerait les séances de
        // l'enseignant non encore atteint. La stale reste donc active.
        $service->sync(0);
        $this->assertTrue(
            (bool) $stale->fresh()->is_active,
            'Tenant laissé incomplet par le budget : archivage reporté, séance non touchée.',
        );

        // Passe suivante : elle REPREND au 2e enseignant (curseur #582), termine
        // le tenant, et archive alors la séance 888 disparue de KLASSCI.
        $service->sync(3600);
        $this->assertFalse(
            (bool) $stale->fresh()->is_active,
            'Tenant clos : la séance absente de KLASSCI est archivée.',
        );
    }
}
