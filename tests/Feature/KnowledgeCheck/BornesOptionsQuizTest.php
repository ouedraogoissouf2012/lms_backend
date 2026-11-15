<?php

declare(strict_types=1);

namespace Tests\Feature\KnowledgeCheck;

use App\Models\KnowledgeCheck;
use App\Support\Evaluation\QuestionOptionLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\JoueUnQuizDeLecon;
use Tests\TestCase;

/**
 * D602 (1) : les options d'un quiz de leçon suivent les bornes des évaluations, lues depuis
 * `QuestionOptionLimits` : au plus MAX_OPTIONS options, de MAX_OPTION_LENGTH caractères
 * chacune. Une seule source : les bornes sont calculées ici DEPUIS les constantes, jamais
 * recopiées, de sorte qu'un changement de la constante se lit des deux côtés.
 *
 * Vrai jeton, les deux types d'école, création ET mise à jour.
 */
final class BornesOptionsQuizTest extends TestCase
{
    use ActsAsTenantUser;
    use JoueUnQuizDeLecon;
    use RefreshDatabase;

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function voies(): iterable
    {
        foreach (self::volets() as $volet => [$mode]) {
            yield "$volet, création" => [$mode, 'création'];
            yield "$volet, mise à jour" => [$mode, 'mise à jour'];
        }
    }

    #[DataProvider('voies')]
    public function test_une_option_de_trop_est_refusee_sans_rien_ecrire(string $volet, string $voie): void
    {
        $this->ecoleEtQuiz($volet);
        $avant = $this->quiz->fresh()?->questions;

        $this->ecrire($voie, self::desOptions(QuestionOptionLimits::MAX_OPTIONS + 1, 5))
            ->assertStatus(422)->assertJsonValidationErrors(['questions.0.options']);

        $this->assertRienEcrit($avant);
    }

    #[DataProvider('voies')]
    public function test_une_option_trop_longue_est_refusee_sans_rien_ecrire(string $volet, string $voie): void
    {
        $this->ecoleEtQuiz($volet);
        $avant = $this->quiz->fresh()?->questions;

        $this->ecrire($voie, self::desOptions(2, QuestionOptionLimits::MAX_OPTION_LENGTH + 1))
            ->assertStatus(422)->assertJsonValidationErrors(['questions.0.options.0']);

        $this->assertRienEcrit($avant);
    }

    /** Témoin : les bornes EXACTES passent. */
    #[DataProvider('voies')]
    public function test_les_bornes_exactes_sont_acceptees(string $volet, string $voie): void
    {
        $this->ecoleEtQuiz($volet);

        $this->ecrire($voie, self::desOptions(QuestionOptionLimits::MAX_OPTIONS, QuestionOptionLimits::MAX_OPTION_LENGTH))
            ->assertSuccessful();
    }

    /** @param  array<int|string, mixed>|null  $avant */
    private function assertRienEcrit(?array $avant): void
    {
        self::assertSame(1, KnowledgeCheck::query()->withoutGlobalScopes()->where('chapter_id', $this->chapitre->id)->count(), 'aucun quiz créé');
        self::assertSame($avant, $this->quiz->fresh()?->questions, 'le quiz n\'est pas modifié');
    }

    /**
     * @param  list<string>  $options
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function ecrire(string $voie, array $options): TestResponse
    {
        $questions = [['question' => 'Une question', 'type' => 'single', 'options' => $options, 'correct_answer' => 0]];

        return $voie === 'création'
            ? $this->asTenant($this->auteur)->postJson('/api/knowledge-checks', [
                'chapter_id' => $this->chapitre->id, 'title' => 'Quiz borné', 'questions' => $questions,
            ])
            : $this->asTenant($this->auteur)->putJson("/api/knowledge-checks/{$this->quiz->id}", ['questions' => $questions]);
    }

    /**
     * Des options DISTINCTES, de la longueur demandée.
     *
     * @return list<string>
     */
    private static function desOptions(int $nombre, int $longueur): array
    {
        $options = [];
        for ($i = 0; $i < $nombre; $i++) {
            $options[] = str_pad("o$i-", $longueur, 'x');
        }

        return $options;
    }
}
