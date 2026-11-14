<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\EcritDesQuestions;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Les bonnes réponses d'une question à options sont parmi ses options (D466).
 *
 * Avant : `questions.*.correct_answers` n'était validé qu'en `nullable|array`. Une bonne réponse
 * absente des options rendait la question IMPOSSIBLE à réussir : l'élève ne remet que le texte
 * d'une option affichée, et la notation le compare à `correct_answers`. L'éditeur du front le
 * produisait : une option renommée après avoir été cochée gardait l'ancien texte.
 *
 * Ce lot porte sur l'INCLUSION (motif `correct_answer_not_among_options`, que le front traduit),
 * le TYPE et le cardinal MAXIMUM. Il n'impose AUCUNE bonne réponse à l'enregistrement : un QCM
 * sans bonne réponse reste enregistrable (un brouillon) ; l'obligation appartient à la
 * publication (lot suivant).
 *
 * Vrai jeton (`asTenant`), création et mise à jour, les deux types d'école.
 */
final class BonnesReponsesParmiLesOptionsTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use EcritDesQuestions;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const MOTIF = 'correct_answer_not_among_options';

    /** @return array<string, array{InstitutionMode}> */
    public static function ecoles(): array
    {
        return [
            'école du LMS' => [InstitutionMode::Standalone],
            'école KLASSCI' => [InstitutionMode::Klassci],
        ];
    }

    /**
     * Le type, les options, les bonnes réponses, la clé de l'erreur, et le motif attendu (ou null).
     *
     * @return array<string, array{InstitutionMode, string, mixed, list<mixed>, string, string|null}>
     */
    public static function refus(): array
    {
        $cas = [
            // L'inclusion : le motif, que le front traduit.
            'qcm, l option renommée après coup' => ['qcm', ['Paris (France)', 'Lyon'], ['Paris'], 'questions.0.correct_answers', self::MOTIF],
            'qcm_multiple, une bonne réponse absente' => ['qcm_multiple', ['a', 'b', 'c'], ['a', 'z'], 'questions.0.correct_answers', self::MOTIF],
            'vrai_faux, ni vrai ni faux' => ['vrai_faux', ['Vrai', 'Faux'], ['Peut-être'], 'questions.0.correct_answers', self::MOTIF],
            // Le cardinal maximum : sans motif.
            'qcm, deux bonnes réponses' => ['qcm', ['a', 'b'], ['a', 'b'], 'questions.0.correct_answers', null],
            'vrai_faux, deux bonnes réponses' => ['vrai_faux', ['Vrai', 'Faux'], ['Vrai', 'Faux'], 'questions.0.correct_answers', null],
            'qcm_multiple, une bonne réponse en double' => ['qcm_multiple', ['a', 'b'], ['a', 'a'], 'questions.0.correct_answers', null],
            // Le type et les bornes : sans motif.
            'une bonne réponse qui n est pas un texte' => ['qcm', ['1', '2'], [1], 'questions.0.correct_answers.0', null],
            'reponse_courte, 27 réponses acceptées' => ['reponse_courte', null, array_fill(0, 27, 'x'), 'questions.0.correct_answers', null],
            'reponse_courte, une réponse de 1 001 caractères' => ['reponse_courte', null, [str_repeat('a', 1001)], 'questions.0.correct_answers.0', null],
        ];
        $jeux = [];
        foreach (self::ecoles() as $ecole => [$mode]) {
            foreach ($cas as $nom => $ligne) {
                $jeux["$ecole, $nom"] = [$mode, ...$ligne];
            }
        }

        return $jeux;
    }

    /** @param  list<mixed>  $bonnes */
    #[DataProvider('refus')]
    public function test_la_creation_refuse(InstitutionMode $mode, string $type, mixed $options, array $bonnes, string $cle, ?string $motif): void
    {
        $auteur = $this->auteurDans($mode);

        $reponse = $this->asTenant($auteur)
            ->postJson('/api/evaluations', $this->creation([$this->question($options, $bonnes, $type)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$cle]);

        self::assertSame($motif, $reponse->json('reason'));
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations', 'post');
        self::assertSame(0, DB::table('evaluations')->count());
    }

    /** @param  list<mixed>  $bonnes */
    #[DataProvider('refus')]
    public function test_la_mise_a_jour_refuse(InstitutionMode $mode, string $type, mixed $options, array $bonnes, string $cle, ?string $motif): void
    {
        $auteur = $this->auteurDans($mode);
        $evaluation = $this->evaluationDe($auteur);

        $reponse = $this->asTenant($auteur)
            ->putJson('/api/evaluations/'.$evaluation->id, ['questions' => [$this->question($options, $bonnes, $type)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$cle]);

        self::assertSame($motif, $reponse->json('reason'));
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}', 'put');
        self::assertSame([['3', '4']], $this->optionsDe($evaluation), 'la question d avant est intacte');
    }

    /**
     * Les témoins : chaque type valide passe, et une question SANS bonne réponse aussi. Ce lot
     * n'impose aucune bonne réponse à l'enregistrement (réserve B de D466).
     */
    #[DataProvider('ecoles')]
    public function test_les_bonnes_reponses_valides_et_les_brouillons_passent(InstitutionMode $mode): void
    {
        $auteur = $this->auteurDans($mode);
        $questions = [
            $this->question(['a', 'b'], ['a']),
            $this->question(['a', 'b', 'c'], ['a', 'c'], 'qcm_multiple'),
            $this->question(['Vrai', 'Faux'], ['Faux'], 'vrai_faux'),
            $this->question(null, ['Paris', 'paris'], 'reponse_courte'),
            ['question' => 'Expliquez.', 'type' => 'dissertation', 'points' => 5, 'options' => null, 'correct_answers' => null],
            $this->question(['a', 'b'], []),
            ['question' => 'Brouillon', 'type' => 'qcm', 'points' => 1, 'options' => ['a', 'b'], 'correct_answers' => null],
        ];

        $creee = $this->asTenant($auteur)->postJson('/api/evaluations', $this->creation($questions))->assertCreated();
        $evaluation = Evaluation::withoutGlobalScopes()->findOrFail($creee->json('data.id'));
        self::assertSame(7, EvaluationQuestion::query()->where('evaluation_id', $evaluation->id)->count());

        $this->asTenant($auteur)->putJson('/api/evaluations/'.$evaluation->id, ['questions' => $questions])->assertOk();
        self::assertSame(
            [['a'], ['a', 'c'], ['Faux'], ['Paris', 'paris'], null, [], null],
            EvaluationQuestion::query()->where('evaluation_id', $evaluation->id)->orderBy('id')->pluck('correct_answers')->all(),
        );
    }
}
