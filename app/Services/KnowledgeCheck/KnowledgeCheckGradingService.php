<?php

declare(strict_types=1);

namespace App\Services\KnowledgeCheck;

use App\Models\KnowledgeCheck;

/**
 * Service de correction automatique des quiz « Testez vos connaissances ».
 *
 * Extrait verbatim de `KnowledgeCheckController` (méthode privée
 * `checkAnswer` + boucle de calcul du score inline dans `submitAttempt`).
 *
 * ## Comportement
 *
 * - `gradeAnswer()` corrige une réponse selon le type de question
 *   (`single`, `multiple`, `true_false`). Retourne `false` si la réponse
 *   est nulle ou le type inconnu. Comportement runtime identique à
 *   l'ancienne `checkAnswer` privée du controller.
 *
 * - `gradeAttempt()` itère sur les questions, comptabilise les bonnes
 *   réponses, calcule le score (% entier), détermine `passed` et
 *   construit le tableau `detailedAnswers` consommé par le frontend.
 *
 * ## DI strict (§1.6 D)
 *
 * Service stateless sans dépendance constructeur — pure logique sur les
 * arguments passés. Pas de Facade.
 *
 * @see app/Http/Controllers/API/KnowledgeCheckAttemptController.php
 */
final class KnowledgeCheckGradingService
{
    /**
     * Vérifie si une réponse utilisateur est correcte pour une question
     * donnée. Logique extraite de `KnowledgeCheckController::checkAnswer`.
     *
     * @param  mixed  $userAnswer
     * @param  mixed  $correctAnswer
     */
    public function gradeAnswer(string $type, $userAnswer, $correctAnswer): bool
    {
        if ($userAnswer === null) {
            return false;
        }

        switch ($type) {
            case 'single':
            case 'true_false':
                return $userAnswer === $correctAnswer;

            case 'multiple':
                if (! is_array($userAnswer) || ! is_array($correctAnswer)) {
                    return false;
                }
                sort($userAnswer);
                sort($correctAnswer);

                return $userAnswer === $correctAnswer;

            default:
                return false;
        }
    }

    /**
     * Calcule le résultat d'une tentative à partir des réponses brutes.
     *
     * Retourne un tableau structuré contenant :
     *   - `correct_answers` (int)  : nombre de bonnes réponses
     *   - `total_questions` (int)  : total des questions
     *   - `score` (int)            : pourcentage entier [0, 100]
     *   - `passed` (bool)          : score ≥ passing_score
     *   - `detailed_answers` (array<int, array>) : détail par question
     *     (avec masquage conditionnel des bonnes réponses / explications
     *     selon `show_correct_answers` / `show_explanation` du quiz).
     *
     * Le caller est responsable de persister `KnowledgeCheckAttempt` et de
     * mettre à jour `ChapterProgress` — cette méthode est pure.
     *
     * @param  array<int|string, mixed>  $userAnswers
     * @return array{
     *     correct_answers: int,
     *     total_questions: int,
     *     score: int,
     *     passed: bool,
     *     detailed_answers: array<int, array<string, mixed>>
     * }
     */
    public function gradeAttempt(KnowledgeCheck $quiz, array $userAnswers): array
    {
        /** @var array<int, array<string, mixed>> $questions */
        $questions = $quiz->questions ?? [];
        $correctAnswers = 0;
        $totalQuestions = count($questions);
        $detailedAnswers = [];

        foreach ($questions as $index => $question) {
            $userAnswer = $userAnswers[$index] ?? null;
            $correctAnswer = $question['correct_answer'] ?? null;
            $type = is_string($question['type'] ?? null) ? $question['type'] : '';
            $isCorrect = $this->gradeAnswer($type, $userAnswer, $correctAnswer);

            if ($isCorrect) {
                $correctAnswers++;
            }

            $detailedAnswers[] = [
                'question_index' => $index,
                'user_answer' => $userAnswer,
                'correct_answer' => $quiz->show_correct_answers ? $correctAnswer : null,
                'is_correct' => $isCorrect,
                'explanation' => $quiz->show_explanation ? ($question['explanation'] ?? null) : null,
            ];
        }

        $score = $totalQuestions > 0
            ? (int) round(($correctAnswers / $totalQuestions) * 100)
            : 0;

        $passed = $score >= (int) $quiz->passing_score;

        return [
            'correct_answers' => $correctAnswers,
            'total_questions' => $totalQuestions,
            'score' => $score,
            'passed' => $passed,
            'detailed_answers' => $detailedAnswers,
        ];
    }
}
