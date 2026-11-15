<?php

declare(strict_types=1);

namespace Tests\Feature\KnowledgeCheck;

use App\Models\KnowledgeCheckAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\JoueUnQuizDeLecon;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * C45 : un quiz de leçon se remet par INDICES d'options, le seul format que le
 * front ait jamais envoyé (`useKnowledgeCheckPlayer.js`, `selectOption(index)`).
 *
 * Chaque réponse est `null` (non répondue), un entier ≥ 0, ou une liste
 * d'entiers ≥ 0 distincts (`multiple`). Toute autre forme est refusée AVANT
 * toute écriture : 422, motif `answer_not_an_option_index` (ADR-906-01), aucune
 * tentative remise, aucun essai consommé. Les bornes (l'indice existe-t-il
 * parmi les options affichées ?) relèvent de C44, qui connaît la présentation.
 *
 * La réponse suit `docs/openapi.yaml`. Vrai jeton, les deux types d'école.
 */
final class RemiseParIndicesTest extends TestCase
{
    use ActsAsTenantUser;
    use JoueUnQuizDeLecon;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/knowledge-checks/{id}/submit';

    /** @return iterable<string, array{0: string, 1: mixed}> */
    public static function refus(): iterable
    {
        $formes = [
            'un texte d\'indice' => '1',
            'le texte de l\'option' => 'Ouagadougou',
            'un booléen' => true,
            'un décimal' => 1.5,
            'un indice négatif' => -1,
            'une liste avec un texte' => ['0'],
            'une liste avec un doublon' => [0, 0],
            'une liste avec un indice négatif' => [-1],
            'une liste imbriquée' => [[0]],
            'un objet' => ['a' => 0],
        ];

        foreach (self::volets() as $volet => [$mode]) {
            foreach ($formes as $cas => $reponse) {
                yield "$volet : $cas" => [$mode, $reponse];
            }
        }
    }

    #[DataProvider('refus')]
    public function test_une_reponse_qui_n_est_pas_un_indice_est_refusee_sans_rien_ecrire(string $volet, mixed $reponse): void
    {
        $this->ecole($volet);

        $refus = $this->remettre([1, $reponse, null]);

        $refus->assertStatus(422)->assertJsonPath('reason', 'answer_not_an_option_index');
        $this->assertMatchesOpenApiResponse($refus, self::CHEMIN, 'post');
        self::assertSame(0, KnowledgeCheckAttempt::query()->where('user_id', $this->eleve->id)->whereNotNull('completed_at')->count(), 'tentatives remises');
    }

    #[DataProvider('volets')]
    public function test_des_indices_justes_sont_notes_et_la_reponse_suit_la_spec(string $volet): void
    {
        $this->ecole($volet);

        $reponse = $this->remettre([1, 0, [2, 0]]);

        $reponse->assertOk()
            ->assertJsonPath('data.score', 100)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.answers.2.user_answer', [2, 0])
            ->assertJsonPath('data.answers.2.correct_answer', [0, 2]);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'data');
    }

    #[DataProvider('volets')]
    public function test_les_questions_non_repondues_sont_admises_et_notees_fausses(string $volet): void
    {
        $this->ecole($volet);

        $reponse = $this->remettre([null, null, []]);

        $reponse->assertOk()->assertJsonPath('data.score', 0)->assertJsonPath('data.correct_answers', 0);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    #[DataProvider('volets')]
    public function test_les_deux_refus_suivent_la_spec_et_son_schema_mord(string $volet): void
    {
        $this->ecole($volet);

        $refus = $this->remettre(['1', 0, null]);
        $refus->assertStatus(422);
        $this->assertOpenApiSchemaRefuseSans($refus, self::CHEMIN, 'post', 'errors');

        // Le refus commun (sans motif) suit le même schéma.
        $sansReponses = $this->asTenant($this->eleve)->postJson("/api/knowledge-checks/{$this->quiz->id}/submit", ['time_spent_seconds' => 5]);
        $sansReponses->assertStatus(422)->assertJsonMissingPath('reason');
        $this->assertMatchesOpenApiResponse($sansReponses, self::CHEMIN, 'post');
    }

    /**
     * @param  list<mixed>  $reponses
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function remettre(array $reponses): TestResponse
    {
        $this->demarrer();

        return $this->soumettre($reponses);
    }

    private function ecole(string $volet): void
    {
        $this->ecoleEtQuiz($volet, ['questions' => [
            ['question' => 'Capitale du Burkina Faso ?', 'type' => 'single', 'options' => ['Bobo-Dioulasso', 'Ouagadougou', 'Koudougou'], 'correct_answer' => 1, 'explanation' => 'Ouagadougou.', 'points' => 1],
            ['question' => 'Le Nakambé traverse le pays.', 'type' => 'true_false', 'options' => ['Vrai', 'Faux'], 'correct_answer' => 0, 'explanation' => null, 'points' => 1],
            ['question' => 'Villes du Burkina ?', 'type' => 'multiple', 'options' => ['Banfora', 'Dakar', 'Kaya'], 'correct_answer' => [0, 2], 'explanation' => null, 'points' => 1],
        ]]);
    }
}
