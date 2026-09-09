<?php

declare(strict_types=1);

namespace Tests\Feature\Matiere;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Matiere\MatiereClassesResolver;
use App\Services\TenantManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Les classes d'une matière, demandées à KLASSCI quand le local ne sait rien (#740).
 *
 * ## Le défaut, mesuré en production le 2026-09-09
 *
 * Créer une leçon sur « Anglais » rendait **403**, alors que les autres
 * matières passaient. La cause :
 *
 *     matiere klassci 3 (Anglais)  -> classes_concernees = []
 *
 * `classes_concernees` avait deux jambes : les séances, et le miroir local
 * `classe_matiere`. Anglais n'a aucune séance, et le miroir est VIDE en
 * production — rien ne l'alimente de façon fiable. Le frontend envoyait donc
 * `classe_id: null`, et `StoreLessonRequest::authorize()` refusait.
 *
 * Les autres matières fonctionnaient uniquement parce qu'elles ont des séances.
 * Le défaut n'était pas propre à Anglais : il frappe **toute matière sans
 * séance**, ce qui était déjà l'énoncé de #740.
 *
 * ## La source exacte, et pourquoi pas l'inférence
 *
 * KLASSCI affirme quelles matières porte chaque classe — mesure du 2026-09-09 :
 *
 *     classe 1 (B2 COM) -> matieres [3, 1, 2]
 *     classe 2 (B3 COM) -> matieres []
 *     classe 4 (BTS)    -> matieres [3, 1, 2]
 *
 * Une autre piste existait : croiser les `combinaisons` (filière × niveau) de
 * la matière avec celles des classes de l'enseignant. Elle donnait le même
 * résultat ici, mais par DÉDUCTION — « il a une classe dans cette filière,
 * donc il y enseigne cette matière ». Sur une donnée qui finit dans
 * `lessons.classe_id`, on prend ce que KLASSCI affirme, pas ce qu'on déduit.
 *
 * ## L'espace rendu reste LOCAL
 *
 * `classes_concernees` alimente `classe_id` à la création de leçon, et
 * `lessons.classe_id` est une clé locale. Une classe que KLASSCI cite mais que
 * le miroir ne connaît pas est donc écartée : `authorize()` la refuserait de
 * toute façon, et proposer une classe inutilisable, c'est proposer une erreur.
 */
final class MatiereClassesFromKlassciTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        app(TenantManager::class)->set($this->institution);

        $this->teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
            'klassci_token' => 'jeton-enseignant',
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * LE défaut corrigé : une matière SANS séance et SANS miroir récupère ses
     * classes auprès de KLASSCI.
     */
    public function test_a_matiere_without_seance_nor_mirror_gets_its_classes_from_klassci(): void
    {
        $b2 = $this->classeLocale(klassciId: 1, libelle: 'B2 COM');
        $bts = $this->classeLocale(klassciId: 4, libelle: 'BTS Genie Civil');
        $this->matiereLocale(klassciId: 3);

        $this->fakeKlassci(
            classesEnseignant: [1, 4],
            matieresParClasse: [1 => [3, 1, 2], 4 => [3, 1, 2]],
        );

        $classes = $this->resolve(klassciMatiereId: 3);

        self::assertSame([$b2->id, $bts->id], array_column($classes, 'id'));
    }

    /**
     * Une classe que KLASSCI ne rattache PAS à cette matière n'est pas proposée.
     * C'est ce qui distingue la source exacte d'une déduction.
     */
    public function test_a_classe_not_carrying_the_matiere_is_not_proposed(): void
    {
        $b2 = $this->classeLocale(klassciId: 1, libelle: 'B2 COM');
        $this->classeLocale(klassciId: 2, libelle: 'B3 COM');
        $this->matiereLocale(klassciId: 3);

        $this->fakeKlassci(
            classesEnseignant: [1, 2],
            matieresParClasse: [1 => [3], 2 => []],
        );

        self::assertSame([$b2->id], array_column($this->resolve(klassciMatiereId: 3), 'id'));
    }

    /**
     * L'espace rendu est LOCAL : une classe citée par KLASSCI mais absente du
     * miroir est écartée, parce que `authorize()` la refuserait.
     */
    public function test_a_classe_absent_from_the_local_mirror_is_dropped(): void
    {
        $b2 = $this->classeLocale(klassciId: 1, libelle: 'B2 COM');
        $this->matiereLocale(klassciId: 3);

        $this->fakeKlassci(
            classesEnseignant: [1, 5],
            matieresParClasse: [1 => [3], 5 => [3]],
        );

        $classes = $this->resolve(klassciMatiereId: 3);

        self::assertSame([$b2->id], array_column($classes, 'id'));
        self::assertNotContains(5, array_column($classes, 'id'), 'un id KLASSCI a fuité');
    }

    /**
     * Le miroir est ALIMENTÉ au passage : le mode dégradé aura enfin de quoi
     * répondre quand KLASSCI sera injoignable.
     */
    public function test_it_feeds_the_local_mirror_on_the_way(): void
    {
        $b2 = $this->classeLocale(klassciId: 1, libelle: 'B2 COM');
        $matiere = $this->matiereLocale(klassciId: 3);

        self::assertSame(0, DB::table('classe_matiere')->count(), 'le miroir doit partir vide');

        $this->fakeKlassci(classesEnseignant: [1], matieresParClasse: [1 => [3]]);
        $this->resolve(klassciMatiereId: 3);

        self::assertSame(1, DB::table('classe_matiere')
            ->where('classe_id', $b2->id)
            ->where('matiere_id', $matiere->id)
            ->count());
    }

    /**
     * La jambe locale garde la priorité : quand le miroir sait, on ne dérange
     * pas KLASSCI. Sans quoi chaque affichage coûterait 1 + N appels.
     */
    public function test_the_local_mirror_takes_precedence_and_spares_klassci(): void
    {
        $b2 = $this->classeLocale(klassciId: 1, libelle: 'B2 COM');
        $matiere = $this->matiereLocale(klassciId: 3);
        DB::table('classe_matiere')->insert([
            'classe_id' => $b2->id,
            'matiere_id' => $matiere->id,
            'institution_id' => $this->institution->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestWithUserToken');
            $mock->shouldNotReceive('fetchManyClassesDetails');
        });

        self::assertSame([$b2->id], array_column($this->resolve(klassciMatiereId: 3), 'id'));
    }

    /**
     * KLASSCI injoignable : une liste vide, jamais une erreur. L'écran affichera
     * « aucune classe », pas une page en 500.
     */
    public function test_a_klassci_failure_yields_an_empty_list(): void
    {
        $this->classeLocale(klassciId: 1, libelle: 'B2 COM');
        $this->matiereLocale(klassciId: 3);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->andThrow(new \RuntimeException('injoignable'));
            $mock->shouldReceive('fetchManyClassesDetails')->andReturn([]);
        });

        self::assertSame([], $this->resolve(klassciMatiereId: 3));
    }

    /**
     * La traduction vers l'espace local est GROUPEE.
     *
     * Une premiere version faisait un `Classe::first()` par classe dans la
     * boucle : un enseignant a 10 classes payait 10 requetes la ou une seule
     * suffit. Le compte est asserte pour que la regression soit visible, et non
     * seulement lente.
     */
    public function test_the_translation_to_local_ids_costs_one_query_not_one_per_classe(): void
    {
        $klassciIds = [1, 2, 4, 5, 7, 8];

        foreach ($klassciIds as $klassciId) {
            $this->classeLocale(klassciId: $klassciId, libelle: 'Classe '.$klassciId);
        }
        $this->matiereLocale(klassciId: 3);

        $this->fakeKlassci(
            classesEnseignant: $klassciIds,
            matieresParClasse: array_fill_keys($klassciIds, [3]),
        );

        $requetes = 0;
        DB::listen(static function (QueryExecuted $query) use (&$requetes): void {
            if (str_contains($query->sql, 'from "classes"') || str_contains($query->sql, 'from `classes`')) {
                $requetes++;
            }
        });

        $classes = $this->resolve(klassciMatiereId: 3);

        self::assertCount(6, $classes, 'les six classes doivent etre proposees');
        self::assertSame(1, $requetes, 'la table `classes` doit etre lue UNE seule fois, pas une fois par classe');
    }

    // ───────────────────── Fixtures ─────────────────────

    private function classeLocale(int $klassciId, string $libelle): Classe
    {
        return Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => $klassciId,
            'libelle' => $libelle,
        ]);
    }

    private function matiereLocale(int $klassciId): Matiere
    {
        return Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => $klassciId,
        ]);
    }

    /**
     * @param  list<int>  $classesEnseignant
     * @param  array<int, list<int>>  $matieresParClasse
     */
    private function fakeKlassci(array $classesEnseignant, array $matieresParClasse): void
    {
        $dashboard = ['data' => ['classes' => array_map(
            static fn (int $id): array => ['id' => $id, 'name' => 'Classe '.$id],
            $classesEnseignant,
        )]];

        $details = [];
        foreach ($matieresParClasse as $classeId => $matiereIds) {
            $details[$classeId] = ['data' => [
                'classe' => ['id' => $classeId, 'libelle' => 'Classe '.$classeId],
                'matieres' => array_map(
                    static fn (int $m): array => ['id' => $m, 'libelle' => 'Matiere '.$m],
                    $matiereIds,
                ),
            ]];
        }

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($dashboard, $details): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('jeton-enseignant', 'me/teacher-dashboard', 'GET')
                ->andReturn($dashboard);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
            $mock->shouldReceive('fetchManyClassesDetails')->andReturn($details);
        });
    }

    /**
     * @return array<int, array{id: int, nom: string}>
     */
    private function resolve(int $klassciMatiereId): array
    {
        return app(MatiereClassesResolver::class)->resolve(
            [],
            $klassciMatiereId,
            $this->institution->id,
            $this->teacher,
        );
    }
}
