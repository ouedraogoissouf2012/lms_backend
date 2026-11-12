<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * C14 : la mise à jour d'une évaluation n'écrit que ce que ses règles valident.
 *
 * Avant : `update` recevait `$request->all()`, filtré par une liste noire. Un
 * PUT écrivait donc sans règle `notes_published` (des notes « publiées dans
 * KLASSCI » sans synchronisation), `is_locked`, `deadline_at`, le coefficient,
 * le barème, le type et les libellés. Et `is_published`, pourtant validé,
 * publiait en contournant `POST /publish` et ses refus.
 *
 * Les deux types d'école, vraie route, vrai jeton (`asTenant`).
 */
final class MiseAJourParListeBlancheTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

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
    public function test_un_put_n_ecrit_que_les_champs_valides(InstitutionMode $mode): void
    {
        [$auteur, $evaluation] = $this->evaluationEn($mode);
        $avant = $evaluation->only(['notes_published', 'is_locked', 'locked_at', 'deadline_at', 'classe_nom', 'matiere_nom', 'enseignant_nom']);

        $this->asTenant($auteur)->putJson($this->de($evaluation), [
            'titre' => 'Contrôle corrigé',
            'notes_published' => true,
            'is_locked' => true,
            'locked_at' => now()->toIso8601String(),
            'deadline_at' => now()->addYear()->toIso8601String(),
            'classe_nom' => 'Forgée',
            'matiere_nom' => 'Forgée',
            'enseignant_nom' => 'Forgé',
        ])->assertOk();

        $evaluation->refresh();
        self::assertSame('Contrôle corrigé', $evaluation->titre);
        self::assertEquals($avant, $evaluation->only(array_keys($avant)));
    }

    #[DataProvider('typesDEcole')]
    public function test_is_published_dans_un_put_est_refuse_avec_son_motif_et_ne_publie_pas(InstitutionMode $mode): void
    {
        // `true`, mais aussi `false` et `null` : aucune route ne sait les
        // appliquer, et `null` finissait en 500 (colonne NOT NULL) avec la
        // règle `prohibited`, qui laisse passer une valeur vide (revue de C14).
        [$auteur, $evaluation] = $this->evaluationEn($mode);

        foreach ([true, false, null] as $valeur) {
            $this->asTenant($auteur)->putJson($this->de($evaluation), ['titre' => 'Volée', 'is_published' => $valeur])
                ->assertUnprocessable()
                ->assertJsonPath('success', false)
                ->assertJsonPath('reason', 'publish_via_dedicated_route');
        }

        $evaluation->refresh();
        self::assertFalse($evaluation->is_published);
        self::assertSame('Contrôle 1', $evaluation->titre);
    }

    #[DataProvider('typesDEcole')]
    public function test_le_coefficient_le_bareme_et_le_type_suivent_les_regles_de_la_creation(InstitutionMode $mode): void
    {
        [$auteur, $evaluation] = $this->evaluationEn($mode);

        // Les règles de la création, à l'identique. `null` (colonne NOT NULL)
        // est traité avec la création, dans C18.
        foreach ([['coefficient' => 'abc'], ['coefficient' => -1], ['bareme' => 'abc'], ['bareme' => -1], ['type' => 'inconnu']] as $corps) {
            $this->asTenant($auteur)->putJson($this->de($evaluation), $corps)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(array_keys($corps));
        }

        $this->asTenant($auteur)->putJson($this->de($evaluation), ['coefficient' => 2, 'bareme' => 10, 'type' => 'mixte'])
            ->assertOk();

        $evaluation->refresh();
        self::assertEquals(2, $evaluation->coefficient);
        self::assertEquals(10, $evaluation->bareme);
        self::assertSame('mixte', $evaluation->type);
    }

    #[DataProvider('typesDEcole')]
    public function test_le_corps_du_front_remplace_la_configuration_et_les_questions(InstitutionMode $mode): void
    {
        // `useCreateQuestions.js:231-237`, le seul appelant du front.
        [$auteur, $evaluation] = $this->evaluationEn($mode);

        $this->asTenant($auteur)->putJson($this->de($evaluation), [
            'duree_minutes' => 45,
            'max_attempts' => 2,
            'shuffle_questions' => true,
            'show_results' => true,
            'questions' => [
                ['question' => 'Première', 'type' => 'qcm', 'points' => 1, 'options' => ['a', 'b'], 'correct_answers' => ['a']],
                ['question' => 'Seconde', 'type' => 'dissertation', 'points' => 4, 'explanation' => 'Parce que.'],
            ],
        ])->assertOk();

        $evaluation->refresh();
        self::assertSame(45, $evaluation->duree_minutes);
        self::assertSame(2, $evaluation->max_attempts);
        self::assertSame(
            ['Première', 'Seconde'],
            EvaluationQuestion::query()->where('evaluation_id', $evaluation->getKey())->orderBy('ordre')->pluck('question')->all(),
        );
        // `explanation` a sa règle : sans elle, `validated()` la retirait.
        self::assertSame(
            [null, 'Parce que.'],
            EvaluationQuestion::query()->where('evaluation_id', $evaluation->getKey())->orderBy('ordre')->pluck('explanation')->all(),
        );
    }

    public function test_la_publication_par_sa_route_refuse_toujours_une_evaluation_sans_question(): void
    {
        [$auteur, $evaluation] = $this->evaluationEn(InstitutionMode::Standalone);
        EvaluationQuestion::query()->where('evaluation_id', $evaluation->getKey())->delete();

        $this->asTenant($auteur)->postJson($this->de($evaluation).'/publish')->assertUnprocessable();

        self::assertFalse($evaluation->refresh()->is_published);
    }

    // ───────────────────────── harnais

    /**
     * L'auteur et son évaluation, dans le type d'école donné.
     *
     * @return array{User, Evaluation}
     */
    private function evaluationEn(InstitutionMode $mode): array
    {
        $ecole = $this->ecole($mode);
        $auteur = $this->membre($ecole, 'enseignant');
        $identite = $mode === InstitutionMode::Klassci
            ? ['klassci_enseignant_id' => 42, 'enseignant_id' => null]
            : ['klassci_enseignant_id' => null, 'enseignant_id' => $auteur->getKey()];
        if ($mode === InstitutionMode::Klassci) {
            $auteur->forceFill(['klassci_enseignant_id' => 42, 'klassci_token' => 'jeton'])->save();
        }

        $evaluation = Evaluation::factory()->create(array_merge($identite, [
            'institution_id' => $ecole->getKey(),
            'titre' => 'Contrôle 1',
            'type' => 'qcm',
            'status' => 'brouillon',
            'is_published' => false,
            'notes_published' => false,
            'is_locked' => false,
            'locked_at' => null,
            'deadline_at' => null,
            'classe_nom' => '6e A',
            'matiere_nom' => 'Maths',
            'enseignant_nom' => 'Auteur',
            'coefficient' => 1,
            'bareme' => 20,
            'date_evaluation' => now()->addDay(),
        ]));
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey()]);

        return [$auteur, $evaluation];
    }

    private function de(Evaluation $evaluation): string
    {
        return '/api/evaluations/'.$evaluation->getKey();
    }
}
