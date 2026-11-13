<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\User;
use App\Services\Evaluation\EvaluationUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * C16 : une évaluation publiée garde au moins une question.
 *
 * `POST /publish` refusait déjà une évaluation sans question, mais un
 * `PUT {questions: []}` vidait une évaluation publiée (200, toujours publiée).
 * L'invariant est désormais écrit en un seul lieu, que les deux voies
 * appellent, avec son motif `evaluation_without_questions`.
 *
 * Les deux types d'école, vraie route, vrai jeton (`asTenant`).
 */
final class PublieeSansQuestionTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        // La resynchronisation du profil (#90) partirait vers KLASSCI avec le
        // jeton de l'enseignant de l'école créée depuis KLASSCI.
        $this->disableKlassciMiddleware();
    }

    /**
     * @return iterable<string, array{InstitutionMode}>
     */
    public static function typesDEcole(): iterable
    {
        yield 'école créée depuis le LMS' => [InstitutionMode::Standalone];
        yield 'école créée depuis KLASSCI' => [InstitutionMode::Klassci];
    }

    #[DataProvider('typesDEcole')]
    public function test_une_evaluation_publiee_ne_se_vide_pas_et_rien_n_est_ecrit(InstitutionMode $mode): void
    {
        // Le refus tombe AVANT toute écriture : le titre envoyé dans le même
        // PUT ne doit pas être écrit non plus.
        [$auteur, $evaluation] = $this->evaluationEn($mode, publiee: true);
        $origine = $this->idsDesQuestions($evaluation);

        $reponse = $this->asTenant($auteur)->putJson($this->de($evaluation), ['titre' => 'Vidée', 'questions' => []])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('reason', 'evaluation_without_questions');
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}', 'put');

        $evaluation->refresh();
        self::assertSame('Contrôle 1', $evaluation->titre);
        self::assertSame('planifiee', $evaluation->status);
        self::assertTrue($evaluation->is_published);
        // L'id, pas seulement le texte : une suppression suivie d'une
        // recréation identique passerait un contrôle sur le texte.
        self::assertSame($origine, $this->idsDesQuestions($evaluation));
    }

    #[DataProvider('typesDEcole')]
    public function test_repasser_en_brouillon_dans_le_meme_put_ne_contourne_pas_le_refus(InstitutionMode $mode): void
    {
        // `status` est libre en PUT (C17) : demander `brouillon` dans le même
        // corps ne doit pas ouvrir la porte au vidage.
        [$auteur, $evaluation] = $this->evaluationEn($mode, publiee: true);
        $origine = $this->idsDesQuestions($evaluation);

        $this->asTenant($auteur)->putJson($this->de($evaluation), ['status' => 'brouillon', 'questions' => []])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'evaluation_without_questions');

        $evaluation->refresh();
        self::assertSame('planifiee', $evaluation->status);
        self::assertSame($origine, $this->idsDesQuestions($evaluation));
    }

    #[DataProvider('typesDEcole')]
    public function test_une_evaluation_publiee_remplace_ses_questions_par_d_autres(InstitutionMode $mode): void
    {
        [$auteur, $evaluation] = $this->evaluationEn($mode, publiee: true);

        $this->asTenant($auteur)->putJson($this->de($evaluation), [
            'questions' => [['question' => 'Nouvelle', 'type' => 'qcm', 'points' => 1, 'options' => ['a'], 'correct_answers' => ['a']]],
        ])->assertOk();

        self::assertSame(['Nouvelle'], $this->questionsDe($evaluation));
    }

    #[DataProvider('typesDEcole')]
    public function test_un_brouillon_peut_vider_ses_questions(InstitutionMode $mode): void
    {
        [$auteur, $evaluation] = $this->evaluationEn($mode, publiee: false);

        $this->asTenant($auteur)->putJson($this->de($evaluation), ['questions' => []])->assertOk();

        self::assertSame([], $this->questionsDe($evaluation));
    }

    #[DataProvider('typesDEcole')]
    public function test_la_publication_sans_question_porte_le_meme_motif(InstitutionMode $mode): void
    {
        [$auteur, $evaluation] = $this->evaluationEn($mode, publiee: false);
        EvaluationQuestion::query()->where('evaluation_id', $evaluation->getKey())->delete();

        $reponse = $this->asTenant($auteur)->postJson($this->de($evaluation).'/publish')
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'evaluation_without_questions')
            ->assertJsonPath('message', 'Une évaluation publiée doit compter au moins une question.');
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/publish', 'post');

        self::assertFalse($evaluation->refresh()->is_published);
    }

    #[DataProvider('typesDEcole')]
    public function test_publiee_est_relue_sur_la_ligne_et_non_sur_le_modele_charge(InstitutionMode $mode): void
    {
        // Une publication concurrente, simulée de façon déterministe : le
        // modèle est chargé brouillon, puis la ligne passe publiée en base
        // avant l'appel. Le remplacement doit relire la ligne verrouillée.
        // La route recharge toujours l'évaluation : seul l'appel direct du
        // service peut tenir un modèle périmé.
        [$auteur, $evaluation] = $this->evaluationEn($mode, publiee: false);
        $origine = $this->idsDesQuestions($evaluation);
        DB::table('evaluations')->where('id', $evaluation->getKey())->update(['is_published' => true, 'status' => 'planifiee']);
        self::assertFalse($evaluation->is_published, 'Le modèle doit être resté périmé.');

        $resultat = app(EvaluationUpdateService::class)->update($evaluation, ['titre' => 'Vidée', 'questions' => []], $auteur);

        self::assertSame(422, $resultat['status']);
        self::assertSame('evaluation_without_questions', $resultat['payload']['reason'] ?? null);
        $ligne = DB::table('evaluations')->where('id', $evaluation->getKey())->first(['titre', 'is_published']);
        self::assertSame('Contrôle 1', $ligne?->titre);
        self::assertEquals(1, $ligne?->is_published);
        self::assertSame($origine, $this->idsDesQuestions($evaluation));
    }

    // ───────────────────────── harnais

    /**
     * @return array{User, Evaluation}
     */
    private function evaluationEn(InstitutionMode $mode, bool $publiee): array
    {
        $ecole = $this->ecole($mode);
        $auteur = $this->membre($ecole, 'enseignant');
        if ($mode === InstitutionMode::Klassci) {
            $auteur->forceFill(['klassci_enseignant_id' => 42, 'klassci_token' => 'jeton'])->save();
        }

        $evaluation = Evaluation::factory()->create([
            'institution_id' => $ecole->getKey(),
            'klassci_enseignant_id' => $mode === InstitutionMode::Klassci ? 42 : null,
            'enseignant_id' => $auteur->getKey(),
            'titre' => 'Contrôle 1',
            'status' => $publiee ? 'planifiee' : 'brouillon',
            'is_published' => $publiee,
            'is_locked' => false,
            'date_evaluation' => now()->addDay(),
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey(), 'question' => 'Question d\'origine']);

        return [$auteur, $evaluation];
    }

    /**
     * @return list<string>
     */
    private function questionsDe(Evaluation $evaluation): array
    {
        /** @var list<string> */
        return EvaluationQuestion::query()->where('evaluation_id', $evaluation->getKey())->orderBy('ordre')->pluck('question')->all();
    }

    /**
     * @return list<int>
     */
    private function idsDesQuestions(Evaluation $evaluation): array
    {
        /** @var list<int> */
        return EvaluationQuestion::query()->where('evaluation_id', $evaluation->getKey())->orderBy('id')->pluck('id')->all();
    }

    private function de(Evaluation $evaluation): string
    {
        return '/api/evaluations/'.$evaluation->getKey();
    }
}
