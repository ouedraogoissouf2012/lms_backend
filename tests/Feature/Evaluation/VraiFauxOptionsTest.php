<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\EcritDesQuestions;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * D532 : une question `vrai_faux` d'évaluation a EXACTEMENT les options ['Vrai', 'Faux'].
 *
 * La page de passage et le corrigé proposent TOUJOURS « Vrai » et « Faux »
 * (`OPTIONS_VRAI_FAUX` du front, une seule source), quelles que soient les options
 * enregistrées, et l'élève remet ce texte exact. Un vrai_faux enregistré avec d'autres
 * options (['Paris', 'Lyon'], bonne réponse ['Paris']) comptait 0 pour tous. Refusé à
 * la création comme à la mise à jour : 422, motif `correct_answer_not_among_options`
 * (D467, un motif par concept). L'ordre et la casse comptent (D544) : la forme est
 * canonique, égale à la constante du front.
 *
 * Vrai jeton, les deux types d'école.
 */
final class VraiFauxOptionsTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use EcritDesQuestions;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const MOTIF = 'correct_answer_not_among_options';

    /** @return array<string, array{InstitutionMode, mixed, list<string>|null}> */
    public static function refus(): array
    {
        $cas = [
            'd autres options' => [['Paris', 'Lyon'], ['Paris']],
            'la casse' => [['vrai', 'faux'], ['vrai']],
            'l ordre' => [['Faux', 'Vrai'], ['Vrai']],
            'une option de trop' => [['Vrai', 'Faux', 'Peut-être'], ['Vrai']],
            'aucune option' => [null, ['Vrai']],
        ];
        $jeux = [];
        foreach (['école du LMS' => InstitutionMode::Standalone, 'école KLASSCI' => InstitutionMode::Klassci] as $ecole => $mode) {
            foreach ($cas as $nom => [$options, $bonnes]) {
                $jeux["$ecole, $nom"] = [$mode, $options, $bonnes];
            }
        }

        return $jeux;
    }

    /** @param  list<string>|null  $bonnes */
    #[DataProvider('refus')]
    public function test_la_creation_refuse(InstitutionMode $mode, mixed $options, ?array $bonnes): void
    {
        $auteur = $this->auteurDans($mode);

        $reponse = $this->asTenant($auteur)
            ->postJson('/api/evaluations', $this->creation([$this->question($options, $bonnes, 'vrai_faux')]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['questions.0.options']);

        self::assertSame(self::MOTIF, $reponse->json('reason'));
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations', 'post');
        self::assertSame(0, DB::table('evaluations')->count());
    }

    /** @param  list<string>|null  $bonnes */
    #[DataProvider('refus')]
    public function test_la_mise_a_jour_refuse(InstitutionMode $mode, mixed $options, ?array $bonnes): void
    {
        $auteur = $this->auteurDans($mode);
        $evaluation = $this->evaluationDe($auteur);

        $reponse = $this->asTenant($auteur)
            ->putJson('/api/evaluations/'.$evaluation->id, ['questions' => [$this->question($options, $bonnes, 'vrai_faux')]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['questions.0.options']);

        self::assertSame(self::MOTIF, $reponse->json('reason'));
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}', 'put');
        self::assertSame([['3', '4']], $this->optionsDe($evaluation), 'la question d avant est intacte');
    }

    /** @return array<string, array{InstitutionMode}> */
    public static function ecoles(): array
    {
        return ['école du LMS' => [InstitutionMode::Standalone], 'école KLASSCI' => [InstitutionMode::Klassci]];
    }

    /** Les témoins : la forme canonique, en réponse ou en brouillon, et un qcm aux options libres. */
    #[DataProvider('ecoles')]
    public function test_la_forme_canonique_et_les_autres_types_passent(InstitutionMode $mode): void
    {
        $auteur = $this->auteurDans($mode);
        $questions = [
            $this->question(['Vrai', 'Faux'], ['Faux'], 'vrai_faux'),
            ['question' => 'Brouillon', 'type' => 'vrai_faux', 'points' => 1, 'options' => ['Vrai', 'Faux'], 'correct_answers' => null],
            $this->question(['Paris', 'Lyon'], ['Paris']),
        ];

        $this->asTenant($auteur)->postJson('/api/evaluations', $this->creation($questions))->assertCreated();

        $evaluation = $this->evaluationDe($auteur);
        $this->asTenant($auteur)->putJson('/api/evaluations/'.$evaluation->id, ['questions' => $questions])->assertOk();
    }
}
