<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\CategorieDeQuestions;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\QuestionDeBanque;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1702 (D3) : le tirage au sort. Une évaluation a une question posée à tous et une règle :
 * « tirer 3 questions de la catégorie Calcul », qui en offre 6 (d'un point chacune). À la
 * publication, la catégorie est COPIÉE dans l'évaluation (le vivier) ; au démarrage, 3 questions
 * du vivier sont tirées, figées sur la copie, reproduites à l'écran, dans la copie et à la note.
 * Vraie route, vrai jeton, hasard fixé (graine 11), contrat.
 */
final class TirageAuSortTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private Institution $ecole;

    private Classe $classe;

    private Matiere $maths;

    private User $auteur;

    private Evaluation $evaluation;

    private EvaluationQuestion $pourTous;

    private CategorieDeQuestions $calcul;

    public function test_le_tirage_est_fige_reproduit_partout_et_ne_fuit_pas_le_vivier(): void
    {
        $this->jeu();
        $this->tirages([['categorie_id' => $this->calcul->id, 'nombre' => 3]])->assertOk()
            ->assertJsonPath('data.0.disponibles', 6)->assertJsonPath('data.0.avertissement', null);
        $this->publier()->assertOk();
        self::assertSame(7, $this->evaluation->questions()->count(), 'Le vivier de 6 est copié, avec la question pour tous.');
        $awa = $this->eleve();
        $binta = $this->eleve();
        $this->app->instance(Randomizer::class, new Randomizer(new Xoshiro256StarStar(11)));

        $this->asTenant($awa)->postJson($this->de().'/start')->assertSuccessful();

        $posees = EvaluationSubmission::query()->where('student_id', $awa->id)->sole()->presentation['questions'] ?? [];
        self::assertCount(4, $posees);
        self::assertContains($this->pourTous->id, $posees);
        self::assertSame($posees, $this->idsALEcran($awa));
        self::assertSame($posees, $this->idsDeLaCopie($awa));
        self::assertSame([$this->pourTous->id], $this->idsALEcran($binta), 'Avant son démarrage, l\'élève ne voit jamais le vivier.');
        $this->asTenant($binta)->getJson('/api/evaluations/student')->assertOk()->assertJsonPath('data.0.questions_count', 4);
    }

    public function test_la_note_porte_sur_les_questions_tirees(): void
    {
        $this->jeu();
        $this->tirages([['categorie_id' => $this->calcul->id, 'nombre' => 3]])->assertOk();
        $this->publier()->assertOk();
        $awa = $this->eleve();
        $this->asTenant($awa)->postJson($this->de().'/start')->assertSuccessful();
        $reponses = [];
        foreach ($this->evaluation->questions()->get() as $question) {
            $reponses[(string) $question->id] = 'juste';
        }

        $this->asTenant($awa)->postJson($this->de().'/submit', ['answers' => $reponses])->assertSuccessful();

        // 4 questions posées d'un point, toutes justes : 20 sur 20 ; les 3 non tirées ne comptent pas.
        self::assertSame('20.00', EvaluationSubmission::query()->sole()->note_sur_20, 'Par le cast decimal:2 : la valeur brute est 20 sous SQLite, 20.00 sous MySQL.');
    }

    public function test_une_banque_modifiee_apres_la_publication_ne_change_rien(): void
    {
        $this->jeu();
        $this->tirages([['categorie_id' => $this->calcul->id, 'nombre' => 3]])->assertOk();
        $this->publier()->assertOk();
        $avant = $this->evaluation->questions()->whereNotNull('tirage_id')->pluck('question')->all();

        QuestionDeBanque::query()->withoutGlobalScope('institution')->update(['question' => 'Réécrite après coup']);
        $this->publier();

        self::assertSame($avant, $this->evaluation->questions()->whereNotNull('tirage_id')->pluck('question')->all());
        self::assertSame(7, $this->evaluation->questions()->count(), 'Une republication ne recopie pas le vivier.');
    }

    public function test_la_publication_refuse_un_vivier_trop_petit_et_des_points_inegaux_qu_elle_nomme(): void
    {
        $this->jeu();
        $this->tirages([['categorie_id' => $this->calcul->id, 'nombre' => 7]])->assertOk()
            ->assertJsonPath('data.0.avertissement', 'La catégorie offre 6 question(s) pour 7 à tirer : au moins le double est recommandé.');

        $trop = $this->publier()->assertUnprocessable()->assertJsonPath('reason', 'question_pool_invalid');
        self::assertSame(['La catégorie « Calcul » offre 6 question(s) pour 7 à tirer.'], $this->refusDuTirage($trop));

        $this->tirages([['categorie_id' => $this->calcul->id, 'nombre' => 3]])->assertOk();
        QuestionDeBanque::query()->withoutGlobalScope('institution')->orderBy('id')->first()?->update(['points' => 3, 'question' => 'La question à trois points']);
        $refus = $this->publier()->assertUnprocessable()->assertJsonPath('reason', 'question_pool_invalid');

        self::assertStringContainsString('« La question à trois points » (3.00 pts)', $this->refusDuTirage($refus)[0] ?? '');
        self::assertSame(1, $this->evaluation->questions()->count(), 'Un refus ne copie rien.');
        self::assertFalse((bool) $this->evaluation->fresh()?->is_published);
    }

    public function test_les_regles_se_definissent_avant_la_publication_par_l_auteur_dans_sa_matiere(): void
    {
        $this->jeu();
        $autre = $this->membre($this->ecole, 'enseignant');
        $sienne = CategorieDeQuestions::query()->create(['institution_id' => $this->ecole->id, 'enseignant_id' => $autre->id, 'matiere_id' => $this->maths->id, 'libelle' => 'À lui']);
        $physique = CategorieDeQuestions::query()->create(['institution_id' => $this->ecole->id, 'enseignant_id' => $this->auteur->id, 'matiere_id' => $this->matiere($this->ecole, 'Physique')->id, 'libelle' => 'Autre matière']);

        $this->tirages([['categorie_id' => $sienne->id, 'nombre' => 1]])->assertUnprocessable()->assertJsonPath('reason', 'question_category_out_of_scope');
        $this->tirages([['categorie_id' => $physique->id, 'nombre' => 1]])->assertUnprocessable()->assertJsonPath('reason', 'question_category_out_of_scope');
        $this->tirages([['categorie_id' => $this->calcul->id, 'nombre' => 1]], $this->membre($this->ecole, 'coordinateur'))->assertForbidden();
        $this->tirages([['categorie_id' => $this->calcul->id, 'nombre' => 1]])->assertOk();
        $this->publier()->assertOk();

        $this->tirages([['categorie_id' => $this->calcul->id, 'nombre' => 2]])->assertStatus(409)->assertJsonPath('reason', 'draw_rules_locked');
    }

    public function test_ecole_klassci_la_matiere_de_l_evaluation_est_lue_sur_son_miroir(): void
    {
        $this->jeu(InstitutionMode::Klassci);
        $this->maths->forceFill(['klassci_id' => 55])->save();
        $this->evaluation->forceFill(['matiere_id' => null, 'klassci_matiere_id' => 55])->save();

        $this->tirages([['categorie_id' => $this->calcul->id, 'nombre' => 2]])->assertOk()->assertJsonPath('data.0.nombre', 2);
    }

    private function jeu(InstitutionMode $mode = InstitutionMode::Standalone): void
    {
        $this->ecole = $this->ecole($mode);
        $this->classe = $this->classe($this->ecole, '6e A');
        $this->maths = $this->matiere($this->ecole, 'Maths');
        // Le programme d'une classe KLASSCI vient de KLASSCI : il ne se rattache ici que dans une école du LMS.
        if ($mode === InstitutionMode::Standalone) {
            $this->programme($this->classe, $this->maths, null);
        }
        $this->auteur = $this->membre($this->ecole, 'enseignant');
        $this->evaluation = Evaluation::factory()->create([
            'institution_id' => $this->ecole->id, 'classe_id' => $this->classe->id, 'classe_nom' => $this->classe->libelle,
            'matiere_id' => $this->maths->id, 'matiere_nom' => $this->maths->libelle, 'enseignant_id' => $this->auteur->id,
            'klassci_evaluation_id' => null, 'klassci_classe_id' => null, 'klassci_matiere_id' => null, 'klassci_enseignant_id' => null,
            'is_published' => false, 'is_online' => true, 'allow_retake' => false, 'max_attempts' => 1, 'show_results' => true,
            'notes_published' => false, 'status' => 'brouillon', 'date_evaluation' => now()->subMinutes(10), 'duree_minutes' => 60,
            'deadline_at' => null, 'bareme' => 20, 'coefficient' => '1.00', 'shuffle_questions' => false,
        ]);
        $this->pourTous = EvaluationQuestion::factory()->create(['evaluation_id' => $this->evaluation->id, 'institution_id' => $this->ecole->id, 'type' => 'qcm', 'options' => ['juste', 'faux'], 'correct_answers' => ['juste'], 'points' => 1, 'ordre' => 1]);
        $this->calcul = CategorieDeQuestions::query()->create(['institution_id' => $this->ecole->id, 'enseignant_id' => $this->auteur->id, 'matiere_id' => $this->maths->id, 'libelle' => 'Calcul']);
        foreach (range(1, 6) as $rang) {
            QuestionDeBanque::query()->create([
                'institution_id' => $this->ecole->id, 'categorie_id' => $this->calcul->id, 'question' => 'Calcul '.$rang,
                'type' => 'qcm', 'points' => 1, 'options' => ['juste', 'faux'], 'correct_answers' => ['juste'],
            ]);
        }
        $this->disableKlassciMiddleware();
    }

    private function eleve(): User
    {
        $eleve = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($eleve, $this->classe, 'actif');

        return $eleve;
    }

    /** @param  list<array{categorie_id: int, nombre: int}>  $regles */
    private function tirages(array $regles, ?User $acteur = null): TestResponse
    {
        $reponse = $this->asTenant($acteur ?? $this->auteur)->putJson($this->de().'/tirages', ['tirages' => $regles]);
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/tirages', 'put');

        return $reponse;
    }

    private function publier(): TestResponse
    {
        $reponse = $this->asTenant($this->auteur)->postJson($this->de().'/publish');
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/publish', 'post');

        return $reponse;
    }

    /** @return list<int> */
    private function idsALEcran(User $eleve): array
    {
        return array_map(static fn (array $q): int => (int) $q['id'], (array) $this->asTenant($eleve)->getJson($this->de())->assertOk()->json('data.questions'));
    }

    /** @return list<int> */
    private function idsDeLaCopie(User $eleve): array
    {
        $reponse = $this->asTenant($eleve)->getJson($this->de().'/my-submission');
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/my-submission', 'get');

        return array_map(static fn (array $q): int => (int) $q['id'], (array) $reponse->assertOk()->json('data.questions'));
    }

    /** @return list<string> le refus de la première règle (`errors["tirages.0"]`) */
    private function refusDuTirage(TestResponse $reponse): array
    {
        $erreurs = (array) $reponse->json('errors');

        return array_values(array_map('strval', (array) ($erreurs['tirages.0'] ?? [])));
    }

    private function de(): string
    {
        return '/api/evaluations/'.$this->evaluation->id;
    }
}
