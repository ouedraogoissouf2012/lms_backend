<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Seances;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\Sync\KlassciSeancesSyncService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * §1.4 PRODUCTION_STANDARDS.md — le job de sync ne doit pas tirer le catalogue
 * entier de l'établissement pour construire un simple filtre.
 *
 * ## État de l'art sur CETTE base (après #741)
 *
 * `fix(seances): les seances viennent de l emploi du temps (#741)` a déjà
 * supprimé le N+1 : {@see \App\Services\Seances\KlassciEmploiTempsSeances}
 * fait UN SEUL appel `emploi-temps` sur une fenêtre de dates. Les ids de matières
 * ne partent PAS dans la requête — ils servent uniquement à répartir la réponse
 * ({@see \App\Services\Seances\Sync\TeacherMatieresResolver::resolve()}).
 *
 * ## Ce qui restait, et que ce correctif traite
 *
 * `KlassciSeancesSyncService` demandait encore `GET matieres`, c'est-à-dire les
 * 452 matières du tenant, uniquement pour en tirer ce filtre. Mesure en direct
 * sur le compte enseignant de démonstration (tenant `presentation`) :
 *
 *     GET matieres             -> 452 matières en 6,00 s
 *     GET me/teacher-dashboard ->   6 matières en 0,73 s
 *
 * ## Le risque, mesuré et non supposé
 *
 * Réduire la liste la transforme en filtre plus étroit : une matière présente à
 * l'emploi du temps mais absente du tableau de bord verrait ses séances écartées.
 * Vérifié sur ce compte : l'emploi du temps porte 2 séances, matières [1, 2],
 * toutes deux couvertes par les 6 du tableau de bord — 0 séance perdue.
 * Ce contrôle vaut pour UN enseignant ; il est à refaire si la liste du tableau
 * de bord s'avérait incomplète pour d'autres profils.
 *
 * ## La source correcte existe déjà et est testée
 *
 * {@see \App\Services\Seances\UserOwnMatieresResolver}, extrait par #725, lit
 * `me/teacher-dashboard` -> `data.matieres`. On la réutilise plutôt que d'en
 * écrire une troisième copie.
 *
 * @see app/Services/Seances/Sync/KlassciSeancesSyncService.php
 */
#[CoversClass(KlassciSeancesSyncService::class)]
final class SyncSeancesScopedToTeacherMatieresTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    private function teacherWithToken(Institution $institution): User
    {
        return User::factory()->for($institution)->create([
            'role' => 'enseignant',
            'name' => 'Prof',
            'klassci_id' => 1001,
            'klassci_token' => 'token-a',
        ]);
    }

    public function test_sync_reads_the_teacher_dashboard_and_never_the_tenant_catalogue(): void
    {
        $institution = Institution::factory()->create();
        $this->teacherWithToken($institution);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            // Le catalogue des 452 matières ne doit PLUS jamais être demandé.
            $mock->shouldReceive('requestWithUserToken')
                ->with('token-a', 'matieres', 'GET')
                ->never();

            $mock->shouldReceive('requestWithUserToken')
                ->with('token-a', 'me/teacher-dashboard', 'GET')
                ->once()
                ->andReturn(['data' => ['matieres' => [
                    ['id' => 10, 'nom' => 'Maths', 'code' => 'MAT1'],
                ]]]);

            // #741 : les séances viennent de l'emploi du temps, en UN appel.
            $mock->shouldReceive('getEmploiTemps')
                ->once()
                ->andReturn(['data' => [[
                    'id' => 42,
                    'matiere' => ['id' => 10],
                    'programmation' => ['date' => '2026-08-01', 'heure_debut' => '2026-08-01T09:00:00Z'],
                    'classe' => ['id' => 501, 'nom' => 'TA'],
                ]]]);

            // Repli pour les autres endpoints (sync de classe).
            $mock->shouldReceive('requestWithUserToken')
                ->andReturn(['data' => ['classe' => ['id' => 501]]]);
        });

        app(TenantManager::class)->reset();
        $stats = app(KlassciSeancesSyncService::class)->sync();

        self::assertSame(1, $stats->teachersChecked);
        self::assertSame(0, $stats->errors);
    }

    public function test_a_teacher_without_matiere_asks_nothing_more(): void
    {
        // Tableau de bord vide : sans matière, pas de filtre, donc aucun appel
        // à l'emploi du temps. On ne retombe JAMAIS sur le catalogue.
        $institution = Institution::factory()->create();
        $this->teacherWithToken($institution);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('token-a', 'matieres', 'GET')
                ->never();

            $mock->shouldReceive('requestWithUserToken')
                ->with('token-a', 'me/teacher-dashboard', 'GET')
                ->once()
                ->andReturn(['data' => ['matieres' => []]]);

            $mock->shouldReceive('getEmploiTemps')->never();

            $mock->shouldReceive('requestWithUserToken')
                ->andReturn(['data' => ['classe' => ['id' => 501]]]);
        });

        app(TenantManager::class)->reset();
        $stats = app(KlassciSeancesSyncService::class)->sync();

        self::assertSame(1, $stats->teachersChecked);
        self::assertSame(0, $stats->errors);
    }
}
