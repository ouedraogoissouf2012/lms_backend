<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\TeachingSeancesFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * La liste des séances d'un enseignant vient de `emploi-temps`, pas de
 * `matieres/{id}.seances_programmees`.
 *
 * ## Le défaut corrigé
 *
 * `seances_programmees` est **systématiquement vide** chez KLASSCI. Mesuré le
 * 2026-09-05 avec un jeton enseignant réel : `matieres/3` renvoyait
 * `seances_programmees: []` et, dans le MÊME corps de réponse,
 * `statistiques.seances.total_programmees: 28`. KLASSCI se contredit lui-même.
 *
 * Conséquence en production : « Séances 0 » sur l'espace enseignant, alors que
 * l'emploi du temps existe. Le LMS lisait une clé que KLASSCI ne remplit pas.
 *
 * `emploi-temps` répond, lui, `success: true` avec les séances réelles.
 *
 * ## Les trois contraintes MESURÉES qui gouvernent ce test
 *
 * 1. **Sans fenêtre de dates, `emploi-temps` ne rend que la semaine courante.**
 *    Une fenêtre explicite est donc obligatoire, jamais optionnelle.
 * 2. **KLASSCI IGNORE le paramètre `matiere_id`.** Vérifié : `?matiere_id=3`
 *    renvoyait 14 séances portant Algorithme et Marketing digital — pas la
 *    matière demandée. Le tri par matière est donc à la charge du LMS.
 * 3. **La forme du payload diffère** : la date est `programmation.date_seance`
 *    (et non `programmation.date`), et `salle` est un OBJET à la racine de la
 *    séance (et non une chaîne dans `programmation`).
 */
final class TeachingSeancesEmploiTempsSourceTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'jeton-enseignant';

    private Institution $institution;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
            'klassci_token' => self::TOKEN,
        ]);
    }

    /**
     * LE défaut : l'enseignant voyait 0 séance. Il doit voir celles de son
     * emploi du temps.
     */
    public function test_the_listing_is_sourced_from_emploi_temps(): void
    {
        $seances = $this->fetch();

        self::assertCount(1, $seances, 'La séance de l\'emploi du temps doit apparaître dans la liste.');
        self::assertSame(5001, $seances->first()['id']);
    }

    /**
     * Contrainte 1. Sans `date_debut`/`date_fin`, KLASSCI ne rend que la semaine
     * courante : l'enseignant perdrait tout le reste de son année.
     */
    public function test_the_date_window_is_always_explicit(): void
    {
        $filtresRecus = null;

        $this->fetch(function (MockInterface $proxy) use (&$filtresRecus): void {
            $proxy->shouldReceive('getEmploiTemps')
                ->andReturnUsing(function (string $token, array $filtres) use (&$filtresRecus): array {
                    $filtresRecus = $filtres;

                    return ['data' => []];
                });
        });

        self::assertIsArray($filtresRecus, 'getEmploiTemps n\'a pas été appelée.');
        self::assertArrayHasKey('date_debut', $filtresRecus, 'Sans date_debut, KLASSCI ne rend que la semaine courante.');
        self::assertArrayHasKey('date_fin', $filtresRecus, 'Sans date_fin, KLASSCI ne rend que la semaine courante.');
        self::assertNotSame($filtresRecus['date_debut'], $filtresRecus['date_fin'], 'La fenêtre doit couvrir plus d\'un jour.');
    }

    /**
     * Contrainte 3. La date vit dans `date_seance`, la salle est un objet à la
     * racine. Lues aux emplacements de `seances_programmees`, les deux seraient
     * nulles — séance sans date ni salle.
     */
    public function test_the_emploi_temps_payload_shape_is_adapted(): void
    {
        $seance = $this->fetch()->first();

        self::assertSame('2026-06-26', $seance['date_seance'], 'La date doit être lue dans programmation.date_seance.');
        self::assertSame('2026-06-26', $seance['programmation']['date']);
        self::assertSame('Salle B12', $seance['salle'], 'La salle est un objet à la RACINE de la séance.');
        self::assertSame('Salle B12', $seance['programmation']['salle']);
    }

    /**
     * Contrainte 2, vue de bout en bout : une séance d'une matière que
     * l'enseignant ne porte pas ne ressort jamais de la liste.
     *
     * ⚠️ Ce test ne prouve PAS le filtre de {@see KlassciEmploiTempsSeances} :
     * le collecteur boucle ensuite sur les matières du tableau de bord et écarte
     * une seconde fois l'intruse. Retirer le filtre de la source laisse donc ce
     * test VERT — vérifié par sabotage. La règle elle-même est verrouillée à
     * l'unité par `KlassciEmploiTempsSeancesTest`. Ce que ce test-ci garantit,
     * c'est le RÉSULTAT observable, indépendamment de la couche qui l'obtient.
     */
    public function test_a_seance_of_a_foreign_matiere_never_reaches_the_listing(): void
    {
        $seances = $this->fetch(function (MockInterface $proxy): void {
            $proxy->shouldReceive('getEmploiTemps')->andReturn(['data' => [
                $this->emploiTempsEntry(matiereId: 99, seanceId: 6001),
            ]]);
        });

        self::assertCount(0, $seances, 'KLASSCI ignore matiere_id : le tri est à la charge du LMS.');
    }

    /**
     * Le pool `matieres/{id}` n'a plus lieu d'être : il ne servait QU'À lire
     * `seances_programmees`. Le supprimer retire N appels HTTP par affichage —
     * la rafale même qui arme le filtre anti-abus de l'hébergement de KLASSCI.
     */
    public function test_the_matieres_details_pool_is_no_longer_called(): void
    {
        $this->fetch(function (MockInterface $proxy): void {
            $proxy->shouldNotReceive('fetchManyMatieresDetails');
        });
    }

    // ───────────────────── Fixtures ─────────────────────

    /**
     * @param  (callable(MockInterface): void)|null  $overrides
     * @return Collection<int, array<string, mixed>>
     */
    private function fetch(?callable $overrides = null): Collection
    {
        $proxy = Mockery::mock(KlassciProxyService::class);

        if ($overrides !== null) {
            $overrides($proxy);
        }

        $proxy->shouldReceive('requestWithUserToken')
            ->with(self::TOKEN, 'me/teacher-dashboard', 'GET')
            ->andReturn(['data' => ['matieres' => [['id' => 11, 'nom' => 'Anglais']]]]);

        $proxy->shouldReceive('getEmploiTemps')
            ->andReturn(['data' => [$this->emploiTempsEntry(matiereId: 11, seanceId: 5001)]]);

        $proxy->shouldReceive('fetchManyClassesDetails')
            ->andReturn([101 => ['data' => ['classe' => ['places_occupees' => 30]]]]);

        $this->app->instance(KlassciProxyService::class, $proxy);

        return $this->app->make(TeachingSeancesFetcher::class)
            ->fetch($this->teacher, self::TOKEN, '2026-01-01', '2026-12-31');
    }

    /**
     * La forme RÉELLE d'une entrée `emploi-temps`, relevée sur KLASSCI le
     * 2026-09-05 : `date_seance` dans `programmation`, `salle` objet à la racine.
     *
     * @return array<string, mixed>
     */
    private function emploiTempsEntry(int $matiereId, int $seanceId): array
    {
        return [
            'id' => $seanceId,
            'classe' => ['id' => 101, 'nom' => 'B2 COM'],
            'matiere' => ['id' => $matiereId, 'nom' => 'Anglais'],
            'salle' => ['id' => 7, 'nom' => 'Salle B12', 'capacite' => 40],
            'programmation' => [
                'date_seance' => '2026-06-26',
                'date_cours' => '2026-06-26',
                'heure_debut' => '2026-06-26T08:00:00.000000Z',
                'heure_fin' => '2026-06-26T10:00:00.000000Z',
                'duree_minutes' => 120,
            ],
        ];
    }
}
