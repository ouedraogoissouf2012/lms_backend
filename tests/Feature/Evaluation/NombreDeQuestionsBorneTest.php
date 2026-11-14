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
 * Le NOMBRE de questions d'une évaluation est borné (B5, partie 2) : 200 au plus
 * (`QuestionCountLimit`), à la création comme à la mise à jour.
 *
 * Avant : `questions` n'avait aucune borne de nombre. Une mise à jour supprime puis recrée
 * toutes les questions : une seule requête pouvait écrire des milliers de lignes, sous la
 * seule borne de `post_max_size`.
 *
 * Vrai jeton (`asTenant`), les deux types d'école.
 */
final class NombreDeQuestionsBorneTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use EcritDesQuestions;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    /** @return array<string, array{InstitutionMode}> */
    public static function ecoles(): array
    {
        return [
            'école du LMS' => [InstitutionMode::Standalone],
            'école KLASSCI' => [InstitutionMode::Klassci],
        ];
    }

    /** Le témoin de la borne : 200 questions passent, en création et en mise à jour. */
    #[DataProvider('ecoles')]
    public function test_deux_cents_questions_sont_acceptees(InstitutionMode $mode): void
    {
        $auteur = $this->auteurDans($mode);

        $creee = $this->asTenant($auteur)->postJson('/api/evaluations', $this->creation($this->questions(200)))->assertCreated();
        $evaluation = Evaluation::withoutGlobalScopes()->findOrFail($creee->json('data.id'));
        self::assertSame(200, $this->nombreDeQuestions($evaluation));

        $this->asTenant($auteur)->putJson('/api/evaluations/'.$evaluation->id, ['questions' => $this->questions(200)])->assertOk();
        self::assertSame(200, $this->nombreDeQuestions($evaluation));
    }

    /** 201 questions à la création : 422 sur `questions`, et rien n'est écrit. */
    #[DataProvider('ecoles')]
    public function test_la_creation_refuse_deux_cent_une_questions(InstitutionMode $mode): void
    {
        $auteur = $this->auteurDans($mode);

        $reponse = $this->asTenant($auteur)
            ->postJson('/api/evaluations', $this->creation($this->questions(201)))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['questions']);

        $this->assertMatchesOpenApiResponse($reponse, '/evaluations', 'post');
        self::assertSame(0, DB::table('evaluations')->count());
        self::assertSame(0, DB::table('evaluation_questions')->count());
    }

    /** 201 questions à la mise à jour : 422, et la question d'avant est intacte (rien n'a été supprimé). */
    #[DataProvider('ecoles')]
    public function test_la_mise_a_jour_refuse_deux_cent_une_questions(InstitutionMode $mode): void
    {
        $auteur = $this->auteurDans($mode);
        $evaluation = $this->evaluationDe($auteur);

        $reponse = $this->asTenant($auteur)
            ->putJson('/api/evaluations/'.$evaluation->id, ['questions' => $this->questions(201)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['questions']);

        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}', 'put');
        self::assertSame([['3', '4']], $this->optionsDe($evaluation), 'la question d avant est intacte');
    }

    /** @return list<array<string, mixed>> */
    private function questions(int $nombre): array
    {
        return array_fill(0, $nombre, $this->question(['3', '4'], ['4']));
    }

    private function nombreDeQuestions(Evaluation $evaluation): int
    {
        return EvaluationQuestion::query()->where('evaluation_id', $evaluation->id)->count();
    }
}
