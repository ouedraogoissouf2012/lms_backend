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
 * PERF (#135) — Garantit que `TeachingSeancesFetcher` n'émet pas d'appel KLASSCI
 * par séance ni par matière : les séances arrivent en UN appel `emploi-temps`,
 * les effectifs de classe en UN pool dédupliqué.
 *
 * Le test verrouille DEUX choses :
 *   1. Correction : sortie identique (séances mappées + effectif + date réalignée).
 *   2. Anti-régression N+1 : `getEmploiTemps` / `fetchManyClassesDetails` appelés
 *      EXACTEMENT une fois, avec TOUS les IDs de classe en une passe.
 *
 * ## Ce qui a changé, et pourquoi c'est plus fort qu'avant
 *
 * Ce test exigeait auparavant UN appel `fetchManyMatieresDetails` pour N matières
 * — un pool, mais un pool de N requêtes HTTP. Ce pool servait uniquement à lire
 * `data.seances_programmees`, une clé que KLASSCI laisse **toujours vide**
 * (mesuré le 2026-09-05). Il a disparu avec la source qu'il alimentait : les
 * séances viennent maintenant d'un unique `emploi-temps`, quel que soit le
 * nombre de matières. Le budget réseau passe donc de « N + |classes| » à
 * « 1 + |classes| ».
 *
 * @see app/Services/Seances/TeachingSeancesFetcher.php
 * @see app/Services/Seances/KlassciEmploiTempsSeances.php
 */
final class TeachingSeancesParallelFetchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_charge_les_seances_et_classes_sans_appel_par_matiere(): void
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
        $seanceA = $this->seancePayload(5001, 101, 11, '2026-06-26', '2026-06-25T08:00:00.000000Z');
        $seanceB = $this->seancePayload(5002, 102, 12, '2026-06-27', '2026-06-25T10:00:00.000000Z');

        $proxy = Mockery::mock(KlassciProxyService::class);

        $proxy->shouldReceive('requestWithUserToken')
            ->once()
            ->with($token, 'me/teacher-dashboard', 'GET')
            ->andReturn(['data' => ['matieres' => [
                ['id' => 11, 'nom' => 'Maths'],
                ['id' => 12, 'nom' => 'Physique'],
            ]]]);

        // UN seul appel emploi-temps pour les 2 matières — et pour 200 aussi :
        // le coût ne dépend plus du nombre de matières.
        $proxy->shouldReceive('getEmploiTemps')
            ->once()
            ->with($token, ['date_debut' => '2026-01-01', 'date_fin' => '2026-12-31'])
            ->andReturn(['data' => [$seanceA, $seanceB]]);

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
        $result = $fetcher->fetch($teacher, $token, '2026-01-01', '2026-12-31');

        $this->assertCount(2, $result);

        $byId = $result->keyBy('id');
        $this->assertSame(30, $byId[5001]['classe']['effectif']);
        $this->assertSame(25, $byId[5002]['classe']['effectif']);

        // Date réalignée sur la date réelle de la séance (contournement bug KLASSCI).
        $this->assertSame('2026-06-26T08:00:00.000000Z', $byId[5001]['programmation']['heure_debut']);
        $this->assertSame('2026-06-27T10:00:00.000000Z', $byId[5002]['programmation']['heure_debut']);
    }

    /**
     * Forme RÉELLE d'une entrée `emploi-temps` : `date_seance` dans
     * `programmation`, `salle` objet à la racine, matière portée par la séance.
     *
     * @return array<string, mixed>
     */
    private function seancePayload(int $id, int $classeId, int $matiereId, string $date, string $heureDebut): array
    {
        return [
            'id' => $id,
            'classe' => ['id' => $classeId, 'nom' => 'Classe '.$classeId],
            'matiere' => ['id' => $matiereId],
            'salle' => ['id' => 1, 'nom' => 'Salle 1'],
            'programmation' => [
                'date_seance' => $date,
                'heure_debut' => $heureDebut,
                'heure_fin' => $heureDebut,
            ],
        ];
    }
}
