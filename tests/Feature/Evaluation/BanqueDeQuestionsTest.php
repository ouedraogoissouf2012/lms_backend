<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\QuestionDeBanque;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1702 (D1) : la banque de questions, `/api/banque/…`. L'enseignant y écrit ses catégories (par
 * matière) et leurs questions, validées comme des questions d'évaluation ; le coordinateur et
 * l'admin la lisent ; la catégorie d'un autre enseignant ou d'une autre école est introuvable.
 * Vraie route, vrai jeton, deux volets, chaque réponse validée par le contrat.
 */
final class BanqueDeQuestionsTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private Institution $ecole;

    private Matiere $maths;

    private User $auteur;

    private const QCM = ['question' => 'Combien font 2 + 2 ?', 'type' => 'qcm', 'points' => 2, 'options' => ['3', '4'], 'correct_answers' => ['4']];

    /** @return iterable<string, array{InstitutionMode}> */
    public static function ecoles(): iterable
    {
        yield 'école du LMS' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    #[DataProvider('ecoles')]
    public function test_l_enseignant_ecrit_sa_banque_de_bout_en_bout(InstitutionMode $mode): void
    {
        $this->jeu($mode);

        $categorie = $this->appel('post', '/banque/categories', ['matiere_id' => $this->maths->id, 'libelle' => ' Fractions '])->assertCreated();
        $id = (int) $categorie->json('data.id');
        $categorie->assertJsonPath('data.libelle', 'Fractions')->assertJsonPath('data.nombre_de_questions', 0);
        $question = $this->appel('post', "/banque/categories/{$id}/questions", self::QCM)->assertCreated();
        $qid = (int) $question->json('data.id');

        $this->appel('get', '/banque/categories?matiere_id='.$this->maths->id)->assertOk()
            ->assertJsonPath('data.0.nombre_de_questions', 1);
        $this->appel('put', "/banque/questions/{$qid}", ['question' => 'Combien font 3 + 3 ?', 'type' => 'qcm', 'options' => ['6', '7'], 'correct_answers' => ['6']])->assertOk()
            ->assertJsonPath('data.correct_answers', ['6']);
        $this->appel('patch', "/banque/categories/{$id}", ['libelle' => 'Fractions simples'])->assertOk()->assertJsonPath('data.libelle', 'Fractions simples');
        $this->appel('get', "/banque/categories/{$id}/questions")->assertOk()->assertJsonPath('data.0.question', 'Combien font 3 + 3 ?');
        $this->appel('delete', "/banque/questions/{$qid}")->assertOk();
        $this->appel('delete', "/banque/categories/{$id}")->assertOk();

        $this->appel('get', '/banque/categories')->assertOk()->assertJsonPath('data', []);
    }

    public function test_qui_lit_et_qui_ecrit_la_banque(): void
    {
        $this->jeu(InstitutionMode::Standalone);
        $id = (int) $this->appel('post', '/banque/categories', ['matiere_id' => $this->maths->id, 'libelle' => 'Géométrie'])->json('data.id');
        $chemin = "/banque/categories/{$id}/questions";
        $acteurs = [
            'auteur' => $this->auteur,
            'coordinateur de l école' => $this->membre($this->ecole, 'coordinateur'),
            'admin de l école' => $this->membre($this->ecole, 'superAdmin'),
            'autre enseignant' => $this->membre($this->ecole, 'enseignant'),
            'élève' => $this->membre($this->ecole, 'etudiant'),
            'coordinateur d une autre école' => $this->membre($this->ecole(), 'coordinateur'),
        ];

        $statuts = [];
        foreach ($acteurs as $qui => $acteur) {
            $statuts[$qui] = [$this->appel('get', $chemin, [], $acteur)->status(), $this->appel('post', $chemin, self::QCM, $acteur)->status()];
        }

        self::assertSame([
            'auteur' => [200, 201],
            'coordinateur de l école' => [200, 403],
            'admin de l école' => [200, 403],
            'autre enseignant' => [404, 404],
            'élève' => [403, 403],
            'coordinateur d une autre école' => [404, 404],
        ], $statuts);
        self::assertSame(1, QuestionDeBanque::query()->withoutGlobalScope('institution')->count());
        $this->appel('get', '/banque/categories', [], $acteurs['autre enseignant'])->assertOk()->assertJsonPath('data', []);
        $this->appel('get', '/banque/categories', [], $acteurs['coordinateur de l école'])->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_une_question_suit_les_regles_d_une_question_d_evaluation(): void
    {
        $this->jeu(InstitutionMode::Standalone);
        $id = (int) $this->appel('post', '/banque/categories', ['matiere_id' => $this->maths->id, 'libelle' => 'Règles'])->json('data.id');
        $chemin = "/banque/categories/{$id}/questions";

        $this->appel('post', $chemin, ['question' => 'Q', 'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['c']])
            ->assertUnprocessable()->assertJsonPath('reason', 'correct_answer_not_among_options');
        $this->appel('post', $chemin, ['question' => 'Q', 'type' => 'vrai_faux', 'options' => ['Oui', 'Non'], 'correct_answers' => ['Oui']])
            ->assertUnprocessable()->assertJsonPath('reason', 'correct_answer_not_among_options');
        $this->appel('post', $chemin, ['question' => 'Q'])->assertUnprocessable()->assertJsonValidationErrors('type');
        self::assertSame(0, QuestionDeBanque::query()->withoutGlobalScope('institution')->count());
    }

    public function test_libelle_en_double_et_matiere_hors_ecole_sont_refuses(): void
    {
        $this->jeu(InstitutionMode::Standalone);
        $this->appel('post', '/banque/categories', ['matiere_id' => $this->maths->id, 'libelle' => 'Algèbre'])->assertCreated();

        $this->appel('post', '/banque/categories', ['matiere_id' => $this->maths->id, 'libelle' => 'Algèbre'])
            ->assertUnprocessable()->assertJsonPath('reason', 'question_category_duplicate');
        $this->appel('post', '/banque/categories', ['matiere_id' => $this->matiere($this->ecole(), 'Ailleurs')->id, 'libelle' => 'Algèbre'])
            ->assertNotFound()->assertJsonPath('reason', 'subject_out_of_scope');
    }

    public function test_supprimer_une_categorie_supprime_ses_questions(): void
    {
        $this->jeu(InstitutionMode::Standalone);
        $id = (int) $this->appel('post', '/banque/categories', ['matiere_id' => $this->maths->id, 'libelle' => 'À jeter'])->json('data.id');
        $this->appel('post', "/banque/categories/{$id}/questions", self::QCM)->assertCreated();

        $this->appel('delete', "/banque/categories/{$id}")->assertOk();

        self::assertSame(0, QuestionDeBanque::query()->withoutGlobalScope('institution')->count());
        $this->appel('get', "/banque/categories/{$id}/questions")->assertNotFound();
    }

    private function jeu(InstitutionMode $mode): void
    {
        $this->ecole = $this->ecole($mode);
        $this->maths = $this->matiere($this->ecole, 'Maths');
        $this->auteur = $this->membre($this->ecole, 'enseignant');
        $this->disableKlassciMiddleware();
    }

    /** @param  array<string, mixed>  $corps */
    private function appel(string $verbe, string $chemin, array $corps = [], ?User $acteur = null): TestResponse
    {
        $reponse = $this->asTenant($acteur ?? $this->auteur)->json(strtoupper($verbe), '/api'.$chemin, $corps);
        $gabarit = preg_replace(['#/banque/categories/\d+#', '#/banque/questions/\d+#', '#\?.*$#'], ['/banque/categories/{id}', '/banque/questions/{id}', ''], $chemin);
        $this->assertMatchesOpenApiResponse($reponse, (string) $gabarit, $verbe);

        return $reponse;
    }
}
