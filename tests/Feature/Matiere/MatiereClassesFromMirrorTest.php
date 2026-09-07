<?php

declare(strict_types=1);

namespace Tests\Feature\Matiere;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Services\Matiere\MatiereClassesResolver;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Les classes d'une matière, y compris quand elle n'a AUCUNE séance (#740).
 *
 * ## Le dernier maillon de la chaîne
 *
 * `classes_concernees` était dérivé des seules séances de la matière. Une
 * matière sans séance — cas réel et courant : « Anglais » chez KLASSCI le
 * 2026-09-06 — n'avait donc aucune classe, le frontend envoyait
 * `classe_id: null`, et la création de leçon échouait en 403.
 *
 * Or la relation existe en clair dans l'autre sens : `classes/{id}` liste ses
 * `matieres`, et ce lien est miroité localement dans `classe_matiere`.
 *
 * ## Les identifiants rendus sont LOCAUX
 *
 * ⚠️ Une version antérieure rendait les identifiants **KLASSCI**, au motif que
 * `StoreLessonRequest` accepte les deux espaces. Une revue adversariale a
 * montré le piège, et il est sérieux.
 *
 * Le frontend prend `classes_concernees[0].id` et le renvoie en `classe_id`.
 * Or la résolution d'un identifiant entrant tranche, par construction, en
 * faveur de l'espace LOCAL. Si le `klassci_id` proposé percute l'`id` local
 * d'une AUTRE classe du même établissement — cas nominal, les deux
 * numérotations démarrant à 1 — la leçon part sur la mauvaise classe, en
 * **201, sans la moindre erreur**. Et `lessons.classe_id` est le seul verrou de
 * visibilité étudiante : les étudiants de la mauvaise promo voient la leçon,
 * ceux de la bonne ne la voient pas.
 *
 * La règle qui en sort : **l'API ne propose que l'espace qu'elle stocke.**
 * Accepter deux espaces en entrée est un service rendu aux clients existants ;
 * en émettre deux, c'est fabriquer soi-même l'ambiguïté.
 */
#[CoversClass(MatiereClassesResolver::class)]
final class MatiereClassesFromMirrorTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        app(TenantManager::class)->set($this->institution);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    /**
     * LE cas qui bloquait : une matière sans séance récupère ses classes.
     */
    public function test_a_matiere_without_any_seance_still_gets_its_classes(): void
    {
        // `klassci_id` volontairement HORS de la plage des auto-incréments
        // locaux : avec `klassci_id: 1`, le test passerait que l'espace soit
        // traduit ou non, les deux valeurs coïncidant par hasard.
        $classe = $this->classe(klassciId: 7001, libelle: 'B2 COM');
        $matiere = $this->matiere(klassciId: 3, libelle: 'Anglais');
        $this->link($classe, $matiere);

        $classes = $this->resolve(seances: [], klassciMatiereId: 3);

        self::assertSame([['id' => $classe->id, 'nom' => 'B2 COM']], $classes);
        self::assertNotSame(7001, $classes[0]['id']);
    }

    /**
     * Les séances restent la source immédiate. Leur `classe.id` vient du
     * payload KLASSCI : il est TRADUIT vers l'espace local avant d'être rendu.
     */
    public function test_seances_remain_a_source_but_are_translated(): void
    {
        $classe = $this->classe(klassciId: 4, libelle: 'BTS Génie Civil');

        $classes = $this->resolve(
            seances: [['classe' => ['id' => 4, 'nom' => 'BTS Génie Civil']]],
            klassciMatiereId: 3,
        );

        self::assertSame([['id' => $classe->id, 'nom' => 'BTS Génie Civil']], $classes);
        self::assertNotSame(4, $classes[0]['id'], 'l\'id KLASSCI a été rendu tel quel');
    }

    /**
     * LE scénario que la revue a mis au jour, joué en entier.
     *
     * Deux classes du même établissement : « L1 Droit » porte l'id LOCAL de
     * « B2 COM » comme `klassci_id`. Si l'API proposait l'espace KLASSCI, le
     * frontend renverrait cet entier et la leçon atterrirait sur « L1 Droit ».
     */
    public function test_a_collision_between_the_two_spaces_cannot_mislead(): void
    {
        $visee = $this->classe(klassciId: 7001, libelle: 'B2 COM');
        $leurre = $this->classe(klassciId: (int) $visee->id, libelle: 'L1 Droit');
        $matiere = $this->matiere(klassciId: 3, libelle: 'Anglais');
        $this->link($visee, $matiere);

        $classes = $this->resolve(seances: [], klassciMatiereId: 3);

        self::assertSame($visee->id, $classes[0]['id']);
        self::assertNotSame($leurre->id, Classe::localIdFor($classes[0]['id'], $this->institution->id));
        // Ce que la création de leçon retiendra de cette proposition :
        self::assertSame($visee->id, Classe::localIdFor($classes[0]['id'], $this->institution->id));
    }

    /**
     * Une classe vue dans une séance mais absente du miroir local n'est pas
     * proposée.
     *
     * Ce n'est pas une perte : `StoreLessonRequest::authorize()` la refuserait
     * en 403, puisqu'il vérifie la table locale. Proposer une classe
     * inutilisable, c'est proposer une erreur.
     */
    public function test_a_seance_classe_absent_from_the_local_mirror_is_not_proposed(): void
    {
        $classes = $this->resolve(
            seances: [['classe' => ['id' => 4242, 'nom' => 'Jamais synchronisée']]],
            klassciMatiereId: 3,
        );

        self::assertSame([], $classes);
    }

    /**
     * Les deux sources se complètent sans se dupliquer.
     *
     * Le dédoublonnage n'est fiable que parce que les deux jambes parlent
     * désormais le MÊME espace. Tant qu'elles pouvaient diverger, la clé de
     * fusion comparait des entiers de numérotations différentes.
     */
    public function test_the_two_sources_merge_without_duplicates(): void
    {
        $classe = $this->classe(klassciId: 7001, libelle: 'B2 COM');
        $matiere = $this->matiere(klassciId: 3, libelle: 'Anglais');
        $this->link($classe, $matiere);

        $classes = $this->resolve(
            seances: [['classe' => ['id' => 7001, 'nom' => 'B2 COM']]],
            klassciMatiereId: 3,
        );

        self::assertCount(1, $classes);
        self::assertSame($classe->id, $classes[0]['id']);
    }

    /**
     * L'ordre est un CONTRAT, parce que le frontend prend `[0]` comme classe
     * pré-sélectionnée.
     *
     * La jambe « séances » suit l'ordre de l'emploi du temps sur une fenêtre
     * GLISSANTE de ±6 mois : sa composition change avec la date. Même
     * enseignant, même matière, deux jours d'écart, et `[0]` pouvait désigner
     * une autre classe — sans que rien ne le signale.
     *
     * L'ordre alphabétique est arbitraire mais STABLE et explicable ; l'ordre
     * précédent était simplement imprévisible.
     */
    public function test_the_order_is_alphabetical_and_does_not_depend_on_the_seances(): void
    {
        $matiere = $this->matiere(klassciId: 3, libelle: 'Anglais');
        foreach (['Zoologie' => 11, 'Anatomie' => 12, 'Mécanique' => 13] as $libelle => $klassciId) {
            $this->link($this->classe(klassciId: $klassciId, libelle: $libelle), $matiere);
        }

        $depuisLeMiroir = array_column($this->resolve(seances: [], klassciMatiereId: 3), 'nom');

        $avecSeanceEnTete = array_column($this->resolve(
            seances: [['classe' => ['id' => 13, 'nom' => 'Mécanique']]],
            klassciMatiereId: 3,
        ), 'nom');

        self::assertSame(['Anatomie', 'Mécanique', 'Zoologie'], $depuisLeMiroir);
        self::assertSame($depuisLeMiroir, $avecSeanceEnTete, 'l\'ordre dépend encore de la source');
    }

    /**
     * Isolation multi-tenant : la classe d'un autre établissement ne doit
     * JAMAIS remonter, même si le `klassci_id` de matière coïncide — il n'est
     * unique que par institution.
     */
    public function test_a_classe_of_another_institution_never_leaks(): void
    {
        $autre = Institution::factory()->create();
        $classeAilleurs = Classe::factory()->create(['institution_id' => $autre->id, 'klassci_id' => 99]);
        $matiereAilleurs = Matiere::factory()->create(['institution_id' => $autre->id, 'klassci_id' => 3]);
        DB::table('classe_matiere')->insert([
            'classe_id' => $classeAilleurs->id,
            'matiere_id' => $matiereAilleurs->id,
            'institution_id' => $autre->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $classes = $this->resolve(seances: [], klassciMatiereId: 3);

        self::assertSame([], $classes, 'La classe d\'un autre établissement ne doit jamais remonter.');
    }

    /**
     * La même isolation sur la jambe SÉANCES : un `classe.id` KLASSCI qui
     * n'existe que chez un AUTRE établissement ne doit pas se traduire.
     */
    public function test_a_seance_classe_of_another_institution_never_translates(): void
    {
        $autre = Institution::factory()->create();
        Classe::factory()->create(['institution_id' => $autre->id, 'klassci_id' => 4]);

        $classes = $this->resolve(
            seances: [['classe' => ['id' => 4, 'nom' => 'Classe étrangère']]],
            klassciMatiereId: 3,
        );

        self::assertSame([], $classes);
    }

    /**
     * Une matière inconnue du miroir ne fait rien remonter — et ne lève pas.
     */
    public function test_an_unknown_matiere_yields_nothing(): void
    {
        self::assertSame([], $this->resolve(seances: [], klassciMatiereId: 404));
    }

    // ───────────────────── Fixtures ─────────────────────

    private function classe(int $klassciId, string $libelle): Classe
    {
        return Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => $klassciId,
            'libelle' => $libelle,
        ]);
    }

    private function matiere(int $klassciId, string $libelle): Matiere
    {
        return Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => $klassciId,
            'libelle' => $libelle,
        ]);
    }

    private function link(Classe $classe, Matiere $matiere): void
    {
        DB::table('classe_matiere')->insert([
            'classe_id' => $classe->id,
            'matiere_id' => $matiere->id,
            'institution_id' => $this->institution->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $seances
     * @return array<int, array{id: int, nom: string}>
     */
    private function resolve(array $seances, int $klassciMatiereId): array
    {
        return app(MatiereClassesResolver::class)->resolve($seances, $klassciMatiereId, $this->institution->id);
    }
}
