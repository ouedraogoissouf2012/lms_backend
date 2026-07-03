<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\KnowledgeCheckAttempt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #368 — garde zéro-division de `KnowledgeCheckAttempt::getPercentageAttribute`
 * (`$this->total_questions === 0`, app/Models/KnowledgeCheckAttempt.php:77).
 *
 * « Une tentative sans question » : un knowledge check vide (0 question, valeur
 * DEFAULT 0 de la colonne NOT NULL) doit donner 0 %, jamais une
 * DivisionByZeroError → 500. Accessor pur — aucune DB nécessaire.
 */
final class KnowledgeCheckAttemptPercentageTest extends TestCase
{
    public function test_tentative_sans_question_donne_zero_pour_cent_pas_une_division_par_zero(): void
    {
        $attempt = new KnowledgeCheckAttempt([
            'total_questions' => 0,
            'correct_answers' => 5,
        ]);

        self::assertSame(0, $attempt->percentage);
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function percentageProvider(): array
    {
        return [
            'tout juste' => [4, 4, 100],
            'moitié' => [2, 4, 50],
            'arrondi 1/3 → 33' => [1, 3, 33],
            'arrondi 2/3 → 67' => [2, 3, 67],
            'zéro bonne réponse' => [0, 4, 0],
        ];
    }

    #[DataProvider('percentageProvider')]
    public function test_pourcentage_arrondi_a_l_entier(int $correct, int $total, int $expected): void
    {
        $attempt = new KnowledgeCheckAttempt([
            'total_questions' => $total,
            'correct_answers' => $correct,
        ]);

        self::assertSame($expected, $attempt->percentage);
    }
}
