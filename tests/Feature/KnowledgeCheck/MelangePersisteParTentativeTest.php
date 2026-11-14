<?php

declare(strict_types=1);

namespace Tests\Feature\KnowledgeCheck;

use App\Models\KnowledgeCheckAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\JoueUnQuizDeLecon;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * C44 : avec un mélange actif, l'élève est noté sur ce qu'il a VU.
 *
 * Avant : `startAttempt` mélangeait les questions et les options sans rien
 * écrire, et la notation comparait la position AFFICHÉE de la remise à la
 * position ENREGISTRÉE. Une bonne réponse pouvait être notée fausse.
 *
 * Après : la tentative naît ouverte au démarrage et garde sa présentation ;
 * l'élève remet toujours par position affichée, et le serveur retraduit. La
 * correction rendue est dans l'espace affiché, celui que lit l'écran.
 *
 * Le hasard est fixé par une graine ; chaque test vérifie que la permutation
 * tirée n'est PAS l'identité, sans quoi il serait vert sans rien prouver.
 * Vrai jeton, les deux types d'école.
 */
final class MelangePersisteParTentativeTest extends TestCase
{
    use ActsAsTenantUser;
    use JoueUnQuizDeLecon;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/knowledge-checks/{id}/submit';

    private const NB_QUESTIONS = 6;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Randomizer::class, new Randomizer(new Xoshiro256StarStar(44)));
    }

    /** @return iterable<string, array{0: string, 1: bool, 2: bool}> */
    public static function melanges(): iterable
    {
        foreach (self::volets() as $volet => [$mode]) {
            yield "$volet : les questions" => [$mode, true, false];
            yield "$volet : les options" => [$mode, false, true];
            yield "$volet : les deux" => [$mode, true, true];
        }
    }

    #[DataProvider('melanges')]
    public function test_les_bonnes_reponses_vues_sont_notees_justes(string $volet, bool $questions, bool $options): void
    {
        $this->ecoleEtQuiz($volet, ['shuffle_questions' => $questions, 'shuffle_options' => $options, 'questions' => self::questions()]);

        $affichees = $this->demarrer()->json('data.questions');
        $this->assertPermutationNonTriviale($affichees, $questions, $options);

        $reponse = $this->soumettre($this->bonnesReponsesAffichees($affichees));

        $reponse->assertOk()->assertJsonPath('data.score', 100)->assertJsonPath('data.correct_answers', self::NB_QUESTIONS);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    #[DataProvider('melanges')]
    public function test_la_correction_est_rendue_dans_l_ordre_affiche(string $volet, bool $questions, bool $options): void
    {
        $this->ecoleEtQuiz($volet, ['shuffle_questions' => $questions, 'shuffle_options' => $options, 'questions' => self::questions()]);
        $affichees = $this->demarrer()->json('data.questions');

        $detail = $this->soumettre(array_fill(0, self::NB_QUESTIONS, 0))->assertOk()->json('data.answers');

        foreach ($affichees as $p => $question) {
            self::assertSame($p, $detail[$p]['question_index']);
            self::assertSame(0, $detail[$p]['user_answer']);
            // L'écran lit `questions[question_index].options[correct_answer]` : c'est la bonne option.
            self::assertSame(self::bonneOption($question['question']), $question['options'][$detail[$p]['correct_answer']]);
        }
    }

    #[DataProvider('volets')]
    public function test_sans_melange_rien_ne_change_et_la_presentation_est_l_identite(string $volet): void
    {
        $this->ecoleEtQuiz($volet, ['questions' => self::questions()]);
        $affichees = $this->demarrer()->json('data.questions');

        self::assertSame(array_column(self::questions(), 'question'), array_column($affichees, 'question'));
        $this->soumettre(array_column(self::questions(), 'correct_answer'))->assertOk()->assertJsonPath('data.score', 100);

        $tentative = KnowledgeCheckAttempt::query()->where('user_id', $this->eleve->id)->sole();
        self::assertSame(range(0, self::NB_QUESTIONS - 1), $tentative->presentation['questions']);
        self::assertNotNull($tentative->completed_at);
    }

    #[DataProvider('volets')]
    public function test_une_remise_sans_tentative_ouverte_est_notee_dans_l_ordre_enregistre(string $volet): void
    {
        // Une tentative démarrée avant ce lot n'a aucune ligne : elle est notée
        // comme avant, dans l'ordre enregistré, et ne garde aucune présentation.
        $this->ecoleEtQuiz($volet, ['shuffle_questions' => true, 'shuffle_options' => true, 'questions' => self::questions()]);

        $this->soumettre(array_column(self::questions(), 'correct_answer'))->assertOk()->assertJsonPath('data.score', 100);

        $tentative = KnowledgeCheckAttempt::query()->where('user_id', $this->eleve->id)->sole();
        self::assertNull($tentative->presentation);
        self::assertNotNull($tentative->completed_at);
    }

    /** @return iterable<string, array{0: string, 1: mixed}> */
    public static function horsBornes(): iterable
    {
        foreach (self::volets() as $volet => [$mode]) {
            yield "$volet : un indice unique" => [$mode, 3];
            yield "$volet : dans une liste" => [$mode, [0, 3]];
        }
    }

    #[DataProvider('horsBornes')]
    public function test_un_indice_hors_des_options_affichees_est_refuse_sans_rien_noter(string $volet, mixed $horsBornes): void
    {
        $this->ecoleEtQuiz($volet, ['shuffle_questions' => true, 'shuffle_options' => true, 'questions' => self::questions()]);
        $this->demarrer();

        $refus = $this->soumettre([$horsBornes, 0, 0, 0, 0, 0]);

        $refus->assertStatus(422)->assertJsonPath('reason', 'answer_not_an_option_index');
        $this->assertMatchesOpenApiResponse($refus, self::CHEMIN, 'post');
        $this->assertAucuneRemise();
    }

    #[DataProvider('volets')]
    public function test_une_reponse_de_trop_est_refusee(string $volet): void
    {
        $this->ecoleEtQuiz($volet, ['questions' => self::questions()]);
        $this->demarrer();

        $this->soumettre(array_fill(0, self::NB_QUESTIONS + 1, 0))
            ->assertStatus(422)->assertJsonPath('reason', 'answer_not_an_option_index');
        $this->assertAucuneRemise();
    }

    #[DataProvider('volets')]
    public function test_un_quiz_modifie_depuis_le_demarrage_est_refuse_sans_rien_noter(string $volet): void
    {
        $this->ecoleEtQuiz($volet, ['shuffle_options' => true, 'questions' => self::questions()]);
        $this->demarrer();
        $modifiees = self::questions();
        array_pop($modifiees[0]['options']);
        $this->quiz->forceFill(['questions' => $modifiees])->save();

        $refus = $this->soumettre(array_fill(0, self::NB_QUESTIONS, 0));

        $refus->assertStatus(409)->assertJsonPath('reason', 'quiz_changed_since_start');
        $this->assertMatchesOpenApiResponse($refus, self::CHEMIN, 'post');
        $this->assertAucuneRemise();
    }

    #[DataProvider('volets')]
    public function test_un_demarrage_sans_remise_ne_consomme_aucun_essai(string $volet): void
    {
        $this->ecoleEtQuiz($volet, ['max_attempts' => 1, 'questions' => self::questions()]);

        $premier = $this->demarrer()->json('data.attempt_id');
        $second = $this->demarrer()->json('data.attempt_id');

        self::assertSame($premier, $second, 'un redémarrage reprend la tentative ouverte');
        self::assertSame(1, KnowledgeCheckAttempt::query()->where('user_id', $this->eleve->id)->count());
        $this->asTenant($this->eleve)->getJson("/api/knowledge-checks/{$this->quiz->id}/my-attempts")
            ->assertOk()->assertJsonCount(0, 'data.attempts')->assertJsonPath('data.best_score', null);

        // L'unique essai reste disponible : la remise le consomme, puis le quota bloque.
        $this->soumettre(array_fill(0, self::NB_QUESTIONS, 0))->assertOk()->assertJsonPath('data.can_retry', false);
        $this->asTenant($this->eleve)->postJson("/api/knowledge-checks/{$this->quiz->id}/start")->assertStatus(400);
    }

    private function assertAucuneRemise(): void
    {
        self::assertSame(0, KnowledgeCheckAttempt::query()->where('user_id', $this->eleve->id)->whereNotNull('completed_at')->count(), 'tentatives remises');
        self::assertSame(1, KnowledgeCheckAttempt::query()->where('user_id', $this->eleve->id)->whereNull('completed_at')->count(), 'la tentative reste ouverte');
    }

    /** @param  list<array{question: string, options: list<string>}>  $affichees */
    private function assertPermutationNonTriviale(array $affichees, bool $questions, bool $options): void
    {
        $enregistrees = self::questions();
        if ($questions) {
            self::assertNotSame(array_column($enregistrees, 'question'), array_column($affichees, 'question'), 'les questions ne sont pas mélangées');
        }
        if ($options) {
            $parTexte = array_column($enregistrees, 'options', 'question');
            $melangees = array_filter($affichees, static fn (array $q): bool => $q['options'] !== $parTexte[$q['question']]);
            self::assertNotSame([], $melangees, 'aucune option n\'est mélangée');
        }
    }

    /**
     * @param  list<array{question: string, options: list<string>}>  $affichees
     * @return list<int>
     */
    private function bonnesReponsesAffichees(array $affichees): array
    {
        return array_map(
            static fn (array $q): int => (int) array_search(self::bonneOption($q['question']), $q['options'], true),
            $affichees,
        );
    }

    private static function bonneOption(string $question): string
    {
        foreach (self::questions() as $q) {
            if ($q['question'] === $question) {
                return $q['options'][$q['correct_answer']];
            }
        }
        self::fail("question inconnue : $question");
    }

    /** @return list<array{question: string, type: string, options: list<string>, correct_answer: int, explanation: null, points: int}> */
    private static function questions(): array
    {
        $questions = [];
        for ($i = 1; $i <= self::NB_QUESTIONS; $i++) {
            $questions[] = [
                'question' => "Question $i", 'type' => 'single',
                'options' => ["Q$i-a", "Q$i-b", "Q$i-c"], 'correct_answer' => $i % 3,
                'explanation' => null, 'points' => 1,
            ];
        }

        return $questions;
    }
}
