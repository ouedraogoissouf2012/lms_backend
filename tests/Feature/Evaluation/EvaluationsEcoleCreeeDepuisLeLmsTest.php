<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * D72 : une école créée depuis le LMS a ses évaluations (E1 : la création et
 * la propriété).
 *
 * Avant E1, `POST /evaluations` y rendait 403 : la création exigeait une
 * identité enseignant KLASSCI, NULL partout dans ce type d'école. La classe et
 * la matière sont désormais LOCALES (`classe_id`, `matiere_id`), jamais un id
 * local sous un nom `klassci_*` (D132), et l'auteur est `enseignant_id`.
 *
 * Vraie route, vrai jeton (`asTenant`) : le tenant est résolu, donc la source
 * choisie par la fabrique est celle de l'école appelante.
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
final class EvaluationsEcoleCreeeDepuisLeLmsTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const CREER = '/api/evaluations';

    // ───────────────────────── la création

    public function test_l_enseignant_designe_cree_son_evaluation_sur_la_classe_et_la_matiere_locales(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $moi);

        $reponse = $this->asTenant($moi)->postJson(self::CREER, $this->corps($classe, $maths, [
            'questions' => [[
                'question' => 'Combien font 2 + 2 ?',
                'type' => 'qcm',
                'points' => 2,
                'options' => ['3', '4'],
                'correct_answers' => ['4'],
            ]],
        ]));

        $reponse->assertCreated()
            ->assertJsonPath('data.classe_id', $classe->getKey())
            ->assertJsonPath('data.matiere_id', $maths->getKey())
            ->assertJsonPath('data.enseignant_id', $moi->getKey())
            ->assertJsonPath('data.klassci_classe_id', null)
            ->assertJsonPath('data.klassci_matiere_id', null);

        $evaluation = Evaluation::withoutGlobalScopes()->sole();
        self::assertSame($ecole->getKey(), $evaluation->institution_id);
        self::assertSame($classe->getKey(), $evaluation->classe_id);
        self::assertSame($maths->getKey(), $evaluation->matiere_id);
        self::assertSame($moi->getKey(), $evaluation->enseignant_id);
        self::assertNull($evaluation->klassci_classe_id);
        self::assertNull($evaluation->klassci_matiere_id);
        self::assertNull($evaluation->klassci_enseignant_id);
        self::assertNull($evaluation->klassci_evaluation_id);
        self::assertSame('6e A', $evaluation->classe_nom);
        self::assertSame('Maths', $evaluation->matiere_nom);
        self::assertSame(1, EvaluationQuestion::query()->where('evaluation_id', $evaluation->getKey())->count());
    }

    public function test_un_enseignant_non_designe_pour_ce_couple_recoit_403_non_designe(): void
    {
        // Désigné pour Maths en 5e B, pas en 6e A : le couple compte, pas la
        // matière seule, ni la classe seule.
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $sixieme = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($sixieme, $maths, $this->membre($ecole, 'enseignant'));
        $this->programme($this->classe($ecole, '5e B'), $maths, $moi);

        $this->asTenant($moi)->postJson(self::CREER, $this->corps($sixieme, $maths))
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('reason', 'non_designe');

        self::assertSame(0, $this->lignes());
    }

    public function test_une_classe_d_une_autre_ecole_rend_422(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($this->classe($ecole, '6e A'), $maths, $moi);
        $ailleurs = $this->classe($this->ecole(), '6e A');

        $this->asTenant($moi)->postJson(self::CREER, $this->corps($ailleurs, $maths))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['classe_id']);

        self::assertSame(0, $this->lignes());
    }

    public function test_sans_classe_ni_matiere_rend_422(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');

        $this->asTenant($moi)->postJson(self::CREER, ['titre' => 'Contrôle 1', 'type' => 'qcm', 'duree_minutes' => 30])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['classe_id', 'matiere_id']);

        self::assertSame(0, $this->lignes());
    }

    public function test_un_identifiant_klassci_est_refuse_dans_une_ecole_creee_depuis_le_lms(): void
    {
        // D132 : jamais d'id local sous un nom `klassci_*`, même dans une
        // requête. Un `klassci_classe_id` reçu ici n'a pas d'espace où vivre.
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $moi);

        foreach (['klassci_classe_id', 'klassci_matiere_id', 'klassci_evaluation_id'] as $champ) {
            $this->asTenant($moi)
                ->postJson(self::CREER, $this->corps($classe, $maths, [$champ => $classe->getKey()]))
                ->assertUnprocessable()
                ->assertJsonPath('reason', 'identifiant_hors_espace');
        }

        self::assertSame(0, $this->lignes());
    }

    public function test_la_gestion_cree_pour_le_formateur_designe_qui_modifie_publie_et_note(): void
    {
        // D136 : l'auteur est le formateur désigné du couple, même quand la
        // gestion crée. Sans cela, T recevrait 403 sur sa propre évaluation.
        $ecole = $this->ecole();
        $gestion = $this->membre($ecole, 'superAdmin');
        $formateur = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $formateur);

        $this->asTenant($gestion)->postJson(self::CREER, $this->corps($classe, $maths, [
            'questions' => [['question' => 'Expliquez.', 'type' => 'dissertation', 'points' => 5]],
        ]))->assertCreated()->assertJsonPath('data.enseignant_id', $formateur->getKey());
        $evaluation = Evaluation::withoutGlobalScopes()->sole();

        $this->asTenant($formateur)->putJson($this->de($evaluation), ['titre' => 'Contrôle du formateur'])->assertOk();
        $this->asTenant($formateur)->postJson($this->de($evaluation).'/publish')->assertOk();

        $question = EvaluationQuestion::query()->where('evaluation_id', $evaluation->getKey())->sole();
        $copie = EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->getKey(),
            'institution_id' => $ecole->getKey(),
            'status' => 'soumis',
            'answers' => [(string) $question->getKey() => 'Une réponse.'],
        ]);
        $this->asTenant($formateur)
            ->postJson($this->de($evaluation).'/submissions/'.$copie->getKey().'/grade', ['points' => [$question->getKey() => 4]])
            ->assertOk();
    }

    public function test_la_gestion_qui_cree_sur_un_couple_sans_formateur_en_est_l_autrice(): void
    {
        $ecole = $this->ecole();
        $gestion = $this->membre($ecole, 'superAdmin');
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, null);

        $this->asTenant($gestion)->postJson(self::CREER, $this->corps($classe, $maths))
            ->assertCreated()
            ->assertJsonPath('data.enseignant_id', $gestion->getKey());
    }

    public function test_une_matiere_d_une_autre_ecole_rend_422(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $this->programme($classe, $this->matiere($ecole, 'Maths'), $moi);
        $ailleurs = $this->matiere($this->ecole(), 'Maths');

        $this->asTenant($moi)->postJson(self::CREER, $this->corps($classe, $ailleurs))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['matiere_id']);

        self::assertSame(0, $this->lignes());
    }

    public function test_un_corps_forge_ne_choisit_ni_l_auteur_ni_l_ecole(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $collegue = $this->membre($ecole, 'enseignant');
        $autre = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $moi);

        $this->asTenant($moi)->postJson(self::CREER, $this->corps($classe, $maths, [
            'enseignant_id' => $collegue->getKey(),
            'institution_id' => $autre->getKey(),
        ]))->assertCreated();

        $evaluation = Evaluation::withoutGlobalScopes()->sole();
        self::assertSame($moi->getKey(), $evaluation->enseignant_id);
        self::assertSame($ecole->getKey(), $evaluation->institution_id);
    }

    public function test_une_mise_a_jour_ne_change_ni_l_auteur_ni_la_classe_ni_la_matiere(): void
    {
        // Les jumeaux locaux de `klassci_*` sont des champs d'identité : un PUT
        // qui transférerait `enseignant_id` donnerait l'évaluation à un collègue.
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $collegue = $this->membre($ecole, 'enseignant');
        $evaluation = $this->evaluationDe($moi, $ecole);
        $autreClasse = $this->classe($ecole, '5e B');
        $autreMatiere = $this->matiere($ecole, 'SVT');
        [$classeAvant, $matiereAvant] = [$evaluation->classe_id, $evaluation->matiere_id];

        $this->asTenant($moi)->putJson($this->de($evaluation), [
            'titre' => 'Contrôle corrigé',
            'enseignant_id' => $collegue->getKey(),
            'classe_id' => $autreClasse->getKey(),
            'matiere_id' => $autreMatiere->getKey(),
        ])->assertOk();

        $evaluation->refresh();
        self::assertSame('Contrôle corrigé', $evaluation->titre);
        self::assertSame($moi->getKey(), $evaluation->enseignant_id);
        self::assertSame($classeAvant, $evaluation->classe_id);
        self::assertSame($matiereAvant, $evaluation->matiere_id);
    }

    public function test_la_gestion_ne_cree_pas_sur_un_couple_non_programme(): void
    {
        $ecole = $this->ecole();
        $gestion = $this->membre($ecole, 'superAdmin');

        $this->asTenant($gestion)
            ->postJson(self::CREER, $this->corps($this->classe($ecole, '6e A'), $this->matiere($ecole, 'Maths')))
            ->assertForbidden()
            ->assertJsonPath('reason', 'non_designe');

        self::assertSame(0, $this->lignes());
    }

    public function test_un_jeton_klassci_perime_ne_fait_partir_aucun_appel(): void
    {
        // Les libellés viennent des tables locales : un compte porteur d'un
        // jeton, dans ce type d'école, n'interroge jamais KLASSCI.
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $moi->forceFill(['klassci_token' => 'jeton-perime'])->save();
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $moi);
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getMatieres');
            $mock->shouldNotReceive('getClasses');
            $mock->shouldNotReceive('requestWithUserToken');
        });
        Http::preventStrayRequests();

        $this->asTenant($moi)->postJson(self::CREER, $this->corps($classe, $maths))
            ->assertCreated()
            ->assertJsonPath('data.matiere_nom', 'Maths');
    }

    // ───────────────────────── la propriété

    public function test_l_auteur_modifie_publie_et_supprime_son_evaluation(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $evaluation = $this->evaluationDe($moi, $ecole);

        $this->asTenant($moi)->putJson($this->de($evaluation), ['titre' => 'Contrôle corrigé'])->assertOk();
        self::assertSame('Contrôle corrigé', $evaluation->refresh()->titre);

        $this->asTenant($moi)->postJson($this->de($evaluation).'/publish')->assertOk();
        self::assertTrue($evaluation->refresh()->is_published);

        $this->asTenant($moi)->deleteJson($this->de($evaluation))->assertOk();
        self::assertSoftDeleted($evaluation);
    }

    public function test_un_collegue_de_la_meme_ecole_ne_touche_pas_a_l_evaluation(): void
    {
        $ecole = $this->ecole();
        $evaluation = $this->evaluationDe($this->membre($ecole, 'enseignant'), $ecole);
        $collegue = $this->membre($ecole, 'enseignant');

        $this->asTenant($collegue)->putJson($this->de($evaluation), ['titre' => 'Volée'])->assertForbidden();
        $this->asTenant($collegue)->postJson($this->de($evaluation).'/publish')->assertForbidden();
        $this->asTenant($collegue)->deleteJson($this->de($evaluation))->assertForbidden();

        $evaluation->refresh();
        self::assertNotSame('Volée', $evaluation->titre);
        self::assertFalse($evaluation->is_published);
        self::assertNull($evaluation->deleted_at);
    }

    // ───────────────────────── les deux volets, sous un conteneur qui survit

    public function test_deux_ecoles_dans_la_meme_methode_recoivent_chacune_leur_source(): void
    {
        // Laravel garde le contrôleur sur l'objet Route : un port reçu par le
        // CONSTRUCTEUR resterait celui de la première école servie. Ce test
        // rougit si l'injection quitte la méthode.
        $klassci = $this->ecole(InstitutionMode::Klassci);
        $sansIdentite = $this->membre($klassci, 'enseignant');
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $moi);
        $programmationKlassci = [
            'klassci_evaluation_id' => 900,
            'klassci_classe_id' => 20,
            'klassci_matiere_id' => 10,
            'titre' => 'Contrôle 1',
            'type' => 'qcm',
            'duree_minutes' => 30,
        ];

        $this->asTenant($sansIdentite)->postJson(self::CREER, $programmationKlassci)
            ->assertForbidden()
            ->assertJsonPath('reason', 'enseignant_klassci_requis');
        $this->asTenant($moi)->postJson(self::CREER, $this->corps($classe, $maths))->assertCreated();
        $this->asTenant($sansIdentite)->postJson(self::CREER, $programmationKlassci)
            ->assertForbidden()
            ->assertJsonPath('reason', 'enseignant_klassci_requis');

        self::assertSame(1, $this->lignes());
    }

    // ───────────────────────── harnais

    /**
     * @param  array<string, mixed>  $surcharges
     * @return array<string, mixed>
     */
    private function corps(Classe $classe, Matiere $matiere, array $surcharges = []): array
    {
        return array_merge([
            'classe_id' => $classe->getKey(),
            'matiere_id' => $matiere->getKey(),
            'titre' => 'Contrôle 1',
            'type' => 'qcm',
            'date_evaluation' => now()->addDay()->toIso8601String(),
            'duree_minutes' => 30,
        ], $surcharges);
    }

    private function evaluationDe(User $auteur, Institution $ecole): Evaluation
    {
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $auteur);

        $evaluation = Evaluation::factory()->create([
            'institution_id' => $ecole->getKey(),
            'classe_id' => $classe->getKey(),
            'matiere_id' => $maths->getKey(),
            'enseignant_id' => $auteur->getKey(),
            'klassci_evaluation_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
            'klassci_enseignant_id' => null,
            'titre' => 'Contrôle 1',
            'status' => 'brouillon',
            'is_published' => false,
            'is_locked' => false,
            'date_evaluation' => now()->addDay(),
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey()]);

        return $evaluation;
    }

    private function de(Evaluation $evaluation): string
    {
        return '/api/evaluations/'.$evaluation->getKey();
    }

    /**
     * Les lignes de la table, toutes écoles confondues : la portée
     * d'établissement du modèle suit le DERNIER tenant servi, et un décompte
     * scopé pourrait rendre 0 pour une ligne écrite dans une autre école.
     */
    private function lignes(): int
    {
        return DB::table('evaluations')->count();
    }
}
