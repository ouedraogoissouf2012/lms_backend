<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Contrat : le frontend envoie des identifiants KLASSCI, et TOUS les champs qui
 * en reçoivent doivent les accepter — de la même façon.
 *
 * ## Le défaut de classe que ce fichier verrouille
 *
 * Deux champs désignant deux entités miroitées de KLASSCI, deux règles
 * DIVERGENTES sur la même question :
 *
 * | Champ | Règle d'origine |
 * |---|---|
 * | `classe_id` | id local **OU** `klassci_id` — assumé et commenté depuis #265 |
 * | `matiere_id` | id local **uniquement** (`Rule::exists('matieres', 'id')`) |
 *
 * La seconde était de surcroît **insatisfiable** : rien n'alimentait la table
 * `matieres`. Toute création de leçon portant une matière échouait en 422 « La
 * matière n'existe pas », et le diagnostic a coûté une soirée entière — parce
 * que le symptôme (403 puis 422) ne désignait jamais la cause.
 *
 * Le même motif s'est répété le même soir sur `chapters.order` : la migration
 * pose `->default(0)`, `ReorderChaptersRequest` accepte `min:0`, et
 * `StoreChapterRequest` exigeait `> 0`. On pouvait DÉPLACER un chapitre en
 * position 0, pas en CRÉER un.
 *
 * ## Pourquoi ce test-ci, et pas un commentaire
 *
 * Un commentaire ne se relit pas quand on ajoute un champ. Ce test échoue.
 *
 * Il porte sur le COMPORTEMENT observable, pas sur la forme des règles : peu
 * importe qu'on valide par `Rule::exists`, par une closure ou par un objet
 * dédié — les deux espaces d'identifiants doivent aboutir. C'est ce qui le rend
 * ouvert à l'extension et fermé à la régression.
 *
 * ## L'invariant qu'il ne faut PAS relâcher
 *
 * Accepter les deux en ENTRÉE ne signifie pas les stocker tels quels :
 * `lessons.classe_id` et `lessons.matiere_id` sont des identifiants LOCAUX. La
 * traduction appartient à `LessonCrudOperationsService`. Mélanger les deux
 * espaces dans une colonne est exactement la faute qui a produit la fuite entre
 * enseignants de #707 — d'où l'assertion finale, qui vérifie la valeur STOCKÉE.
 */
final class KlassciIdentifierDualityTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classe $classe;

    private Matiere $matiere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->create(['institution_id' => $institution->id]);

        $this->classe = Classe::factory()->create([
            'institution_id' => $institution->id,
            'klassci_id' => 4001,
        ]);
        $this->matiere = Matiere::factory()->create([
            'institution_id' => $institution->id,
            'klassci_id' => 5001,
        ]);
    }

    /**
     * Les quatre combinaisons doivent aboutir. Trois passaient déjà ; c'est la
     * quatrième — matière désignée par son id KLASSCI — qui bloquait tout.
     *
     * @param  'local'|'klassci'  $espaceClasse
     * @param  'local'|'klassci'  $espaceMatiere
     */
    #[DataProvider('combinaisonsDIdentifiants')]
    public function test_both_identifier_spaces_are_accepted(string $espaceClasse, string $espaceMatiere): void
    {
        $this->createLesson(
            classeId: $this->idFor($this->classe->id, (int) $this->classe->klassci_id, $espaceClasse),
            matiereId: $this->idFor($this->matiere->id, (int) $this->matiere->klassci_id, $espaceMatiere),
        )->assertStatus(201);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function combinaisonsDIdentifiants(): array
    {
        return [
            'classe locale + matière locale' => ['local', 'local'],
            'classe locale + matière KLASSCI' => ['local', 'klassci'],
            'classe KLASSCI + matière locale' => ['klassci', 'local'],
            'classe KLASSCI + matière KLASSCI' => ['klassci', 'klassci'],
        ];
    }

    /**
     * L'invariant à ne jamais relâcher : quel que soit l'espace d'ENTRÉE, ce
     * qui est STOCKÉ est l'identifiant LOCAL. Sans cette assertion, accepter
     * les deux reviendrait à mélanger deux espaces dans une colonne — la faute
     * qui a produit la fuite entre enseignants de #707.
     */
    public function test_whatever_the_input_space_the_stored_id_is_local(): void
    {
        $reponse = $this->createLesson(
            classeId: (int) $this->classe->klassci_id,
            matiereId: (int) $this->matiere->klassci_id,
        )->assertStatus(201);

        $reponse->assertJsonPath('data.classe_id', $this->classe->id);
        $reponse->assertJsonPath('data.matiere_id', $this->matiere->id);
    }

    /**
     * Le pendant indispensable : la dualité n'est pas un passe-droit. Un
     * identifiant d'un AUTRE établissement doit rester refusé dans les deux
     * espaces, sans quoi on aurait troqué un blocage contre une fuite.
     */
    public function test_an_identifier_of_another_institution_is_still_refused(): void
    {
        $ailleurs = Institution::factory()->create();
        $classeAilleurs = Classe::factory()->create(['institution_id' => $ailleurs->id, 'klassci_id' => 9001]);

        $this->createLesson(classeId: (int) $classeAilleurs->klassci_id, matiereId: null)
            ->assertStatus(403);
    }

    // ───────────────────── Fixtures ─────────────────────

    private function idFor(int $local, int $klassci, string $espace): int
    {
        return $espace === 'local' ? $local : $klassci;
    }

    private function createLesson(int $classeId, ?int $matiereId): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->teacher->createToken('dualite')->plainTextToken,
        ])->postJson('/api/lessons', array_filter([
            'title' => 'Leçon de contrat',
            'type' => 'cours',
            'classe_id' => $classeId,
            'matiere_id' => $matiereId,
        ], static fn ($v): bool => $v !== null));
    }
}
