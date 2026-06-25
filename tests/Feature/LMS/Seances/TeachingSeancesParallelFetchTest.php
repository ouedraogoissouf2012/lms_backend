<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\TeachingSeancesFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * PERF (#135) — Garantit que `TeachingSeancesFetcher` charge les détails matières
 * et les effectifs de classe en POOLS PARALLÈLES (un appel batch chacun), et plus
 * en boucle séquentielle `matieres/{id}` + `classes/{id}` par séance (N+1).
 *
 * Le test verrouille DEUX choses :
 *   1. Correction : sortie identique (séances mappées + effectif + date réalignée).
 *   2. Anti-régression N+1 : `fetchManyMatieresDetails` / `fetchManyClassesDetails`
 *      appelés EXACTEMENT une fois, avec TOUS les IDs en une passe.
 *
 * @see app/Services/Seances/TeachingSeancesFetcher.php
 * @see app/Services/Klassci/KlassciBatchFetcher.php
 */
final class TeachingSeancesParallelFetchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_charge_matieres_et_classes_en_pool_sans_n_plus_1(): void
    {
        $institution = Institution::factory()->create();
        $teacher = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'enseignant',
            'klassci_id' => 777,
        ]);
        $token = 'klassci-user-token';

        // Deux séances déjà présentes en local → ensureLocalSeanceExists retourne
        // tôt (pas de création, pas de classeSync) : on isole le chemin réseau.
        Seance::factory()->create([
            'institution_id' => $institution->id,
            'klassci_seance_id' => 5001,
            'klassci_classe_id' => 101,
        ]);
        Seance::factory()->create([
            'institution_id' => $institution->id,
            'klassci_seance_id' => 5002,
            'klassci_classe_id' => 102,
        ]);

        // KLASSCI date heure_debut au JOUR COURANT (bug source) → doit être réaligné.
        $seanceA = $this->seancePayload(5001, 101, '2026-06-26', '2026-06-25T08:00:00.000000Z');
        $seanceB = $this->seancePayload(5002, 102, '2026-06-27', '2026-06-25T10:00:00.000000Z');

        $proxy = Mockery::mock(KlassciProxyService::class);

        $proxy->shouldReceive('requestWithUserToken')
            ->once()
            ->with($token, 'me/teacher-dashboard', 'GET')
            ->andReturn(['data' => ['matieres' => [
                ['id' => 11, 'nom' => 'Maths'],
                ['id' => 12, 'nom' => 'Physique'],
            ]]]);

        // UN seul appel batch pour les 2 matières (était 2 appels séquentiels).
        $proxy->shouldReceive('fetchManyMatieresDetails')
            ->once()
            ->with([11, 12], $token)
            ->andReturn([
                11 => ['data' => ['seances_programmees' => [$seanceA]]],
                12 => ['data' => ['seances_programmees' => [$seanceB]]],
            ]);

        // UN seul appel batch dédupliqué pour les 2 classes (était 1 appel/séance).
        $proxy->shouldReceive('fetchManyClassesDetails')
            ->once()
            ->with([101, 102], $token)
            ->andReturn([
                101 => ['data' => ['classe' => ['places_occupees' => 30]]],
                102 => ['data' => ['classe' => ['places_occupees' => 25]]],
            ]);

        $this->app->instance(KlassciProxyService::class, $proxy);

        /** @var TeachingSeancesFetcher $fetcher */
        $fetcher = $this->app->make(TeachingSeancesFetcher::class);
        $result = $fetcher->fetch($teacher, $token);

        $this->assertCount(2, $result);

        $byId = $result->keyBy('id');
        $this->assertSame(30, $byId[5001]['classe']['effectif']);
        $this->assertSame(25, $byId[5002]['classe']['effectif']);

        // Date réalignée sur la date réelle de la séance (contournement bug KLASSCI).
        $this->assertSame('2026-06-26T08:00:00.000000Z', $byId[5001]['programmation']['heure_debut']);
        $this->assertSame('2026-06-27T10:00:00.000000Z', $byId[5002]['programmation']['heure_debut']);
    }

    /**
     * @return array<string, mixed>
     */
    private function seancePayload(int $id, int $classeId, string $date, string $heureDebut): array
    {
        return [
            'id' => $id,
            'classe' => ['id' => $classeId, 'nom' => 'Classe '.$classeId],
            'programmation' => [
                'date' => $date,
                'heure_debut' => $heureDebut,
                'heure_fin' => $heureDebut,
                'salle' => 'Salle 1',
            ],
        ];
    }
}
