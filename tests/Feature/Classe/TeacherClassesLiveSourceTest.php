<?php

declare(strict_types=1);

namespace Tests\Feature\Classe;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Classe\TeacherClassesQueryService;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * « Mes Classes » demande ses classes à KLASSCI, et retombe sur le miroir (#712).
 *
 * ## Deux défauts corrigés, mesurés en production le 2026-09-09
 *
 * **1. La source était vide, et le restait.** L'écran lisait uniquement les
 * miroirs locaux `matiere_enseignant` et `classe_matiere` — tous deux à 0 ligne
 * — alors que le tableau de bord affichait 4 classes. Ces miroirs ne se
 * remplissent que si l'enseignant visite une page précise : en dépendre pour
 * l'affichage rendait l'écran fonction du parcours de navigation.
 *
 * **2. Et même remplis, l'écran aurait été faux.** Le service rendait
 * `'id' => $classe->id`, l'identifiant LOCAL. Or le frontend fusionne cette
 * réponse avec le référentiel `/proxy/classes` — du KLASSCI — par `id` :
 *
 *     parId.set(String(classe.id), classe)            // référentiel KLASSCI
 *     const reference = parId.get(String(classe?.id)) // notre réponse
 *
 * L'appariement n'aurait jamais eu lieu et les effectifs seraient restés à
 * « — ». Ce défaut était invisible tant que la liste était vide.
 *
 * ## Pourquoi l'espace KLASSCI ici, alors que #740 impose le LOCAL ailleurs
 *
 * Ce n'est pas une contradiction. `classes_concernees` doit rendre du LOCAL
 * parce que sa valeur est STOCKÉE dans `lessons.classe_id`. Ici rien n'est
 * stocké : l'identifiant sert à fusionner avec KLASSCI et à naviguer vers lui.
 * La règle tient dans les deux cas — on émet l'espace que le consommateur
 * consomme.
 *
 * ## La mesure qui fonde ce choix
 *
 * `GET me/teacher-dashboard` avec le jeton de l'enseignant, en production :
 * `data.classes` rend les 4 classes avec leurs ids KLASSCI — exactement ce que
 * le tableau de bord affiche, en un seul appel authentifié.
 */
final class TeacherClassesLiveSourceTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
            'klassci_token' => 'jeton-enseignant',
            'klassci_enseignant_id' => 9,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * LE défaut : l'écran affiche les classes dès la première visite, sans
     * dépendre d'un miroir que rien n'a encore rempli.
     */
    public function test_the_classes_come_from_klassci_on_the_first_visit(): void
    {
        $this->fakeDashboardClasses([
            ['id' => 1, 'name' => 'B2 COM'],
            ['id' => 4, 'name' => 'BTS Genie Civil'],
        ]);

        $classes = app(TeacherClassesQueryService::class)->listFor($this->teacher);

        // L'ordre est un CONTRAT : la liste est rendue en cartes, et un ordre
        // qui suit celui de KLASSCI ferait sauter les cartes d'un chargement à
        // l'autre. Le tri par libellé reprend le comportement antérieur
        // (`orderBy('libelle')`), le seul qui soit stable.
        self::assertSame(['B2 COM', 'BTS Genie Civil'], array_column($classes, 'libelle'));
        self::assertSame([1, 4], array_column($classes, 'id'));
    }

    /**
     * L'espace des identifiants est celui de KLASSCI — sans quoi la fusion
     * frontend avec `/proxy/classes` n'apparie rien et les effectifs
     * disparaissent.
     */
    public function test_the_identifiers_are_the_klassci_ones_never_the_local_ones(): void
    {
        $locale = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 7001,
            'libelle' => 'B2 COM',
        ]);

        $this->fakeDashboardClasses([['id' => 7001, 'name' => 'B2 COM']]);

        $classes = app(TeacherClassesQueryService::class)->listFor($this->teacher);

        self::assertSame(7001, $classes[0]['id']);
        self::assertNotSame($locale->id, $classes[0]['id'], 'identifiant LOCAL rendu par erreur');
    }

    /**
     * L'effectif local enrichit la réponse quand la classe est miroitée, sans
     * jamais conditionner sa présence dans la liste.
     */
    public function test_a_mirrored_classe_carries_its_local_effectif(): void
    {
        Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 7001,
            'libelle' => 'B2 COM',
            'effectif' => 30,
        ]);

        $this->fakeDashboardClasses([
            ['id' => 7001, 'name' => 'B2 COM'],
            ['id' => 9999, 'name' => 'Jamais miroitee'],
        ]);

        $classes = collect(app(TeacherClassesQueryService::class)->listFor($this->teacher))->keyBy('id');

        self::assertSame(30, $classes[7001]['effectif']);
        self::assertNull($classes[9999]['effectif'], 'une classe non miroitee ne doit pas inventer un effectif');
    }

    /**
     * KLASSCI injoignable : on DÉGRADE sur le miroir local, on ne vide pas
     * l'écran. C'est le rôle que le miroir aurait toujours dû avoir.
     */
    public function test_a_klassci_failure_degrades_onto_the_local_mirror(): void
    {
        $this->mirrorOneClasse(klassciClasseId: 7001, klassciMatiereId: 3);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->andThrow(new \RuntimeException('KLASSCI injoignable'));
        });

        $classes = app(TeacherClassesQueryService::class)->listFor($this->teacher);

        self::assertSame([7001], array_column($classes, 'id'), 'le repli doit rendre l\'espace KLASSCI lui aussi');
    }

    /**
     * Les deux sources muettes : une liste vide, jamais une erreur.
     */
    public function test_both_sources_silent_yields_an_empty_list(): void
    {
        $this->fakeDashboardClasses([]);

        self::assertSame([], app(TeacherClassesQueryService::class)->listFor($this->teacher));
    }

    // ───────────────────── Fixtures ─────────────────────

    private function mirrorOneClasse(int $klassciClasseId, int $klassciMatiereId): void
    {
        $classe = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => $klassciClasseId,
            'libelle' => 'B2 COM',
        ]);
        $matiere = Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => $klassciMatiereId,
        ]);

        DB::table('classe_matiere')->insert([
            'classe_id' => $classe->id,
            'matiere_id' => $matiere->id,
            'institution_id' => $this->institution->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('matiere_enseignant')->insert([
            'klassci_matiere_id' => $klassciMatiereId,
            'klassci_enseignant_id' => 9,
            'status' => 'active',
            'institution_id' => $this->institution->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $classes
     */
    private function fakeDashboardClasses(array $classes): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($classes): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('jeton-enseignant', 'me/teacher-dashboard', 'GET')
                ->andReturn(['data' => ['classes' => $classes]]);
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
        });
    }
}
