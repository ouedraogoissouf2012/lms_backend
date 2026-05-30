<?php

declare(strict_types=1);

namespace App\Services\Quiz;

use App\Models\QuizAttempt;
use App\Models\QuizQuestion;

/**
 * Logique de correction automatique des quiz extraite des modèles
 * Quiz/QuizAttempt/QuizQuestion vers un service dédié (PERF-04 batch 1).
 *
 * ## Pourquoi extraire
 *
 * `QuizAttempt::autoGrade()` orchestrait l'accumulation de points par
 * itération sur les questions (36 lignes). `QuizQuestion::checkAnswer()`
 * branchait sur le type pour décider de la correction (29 lignes).
 * `QuizQuestion::calculatePoints()` wrappait. Ces 3 méthodes formaient
 * la logique métier « grading » et n'avaient rien à faire dans des modèles
 * Eloquent (§1.1 manifest : « Models = state + relations + scopes,
 * pas de logique métier »).
 *
 * ## Comportement
 *
 * Aucun changement runtime — logique déplacée verbatim. Les méthodes des
 * modèles deviennent des thin wrappers qui délèguent au service pour
 * préserver les call sites existants (test infrastructure + backward-compat
 * sur les controllers).
 *
 * ## DI strict (§1.6 D)
 *
 * Service sans dépendance constructeur — pure logique sur les models passés
 * en argument. Pas de Facade.
 *
 * @see app/Models/QuizAttempt.php::autoGrade (thin wrapper qui délègue)
 * @see app/Models/QuizQuestion.php::checkAnswer (thin wrapper)
 */
class QuizGradingService
{
    /**
     * Vérifie si la réponse user à une question est correcte.
     *
     * Retourne `null` quand la question requiert une correction manuelle
     * (short_answer, essay). Le caller doit gérer ce cas explicitement.
     *
     * @param  mixed  $userAnswer  Single answer ID, array of IDs (multiple_response), or raw input.
     */
    public function checkAnswer(QuizQuestion $question, $userAnswer): ?bool
    {
        switch ($question->type) {
            case 'multiple_choice':
                // Une seule réponse correcte
                return $question->answers()
                    ->where('id', $userAnswer)
                    ->where('is_correct', true)
                    ->exists();

            case 'multiple_response':
                // Plusieurs réponses correctes — exact set match
                $correctIds = $question->getCorrectAnswers()->pluck('id')->sort()->values()->toArray();
                $userIds = is_array($userAnswer) ? collect($userAnswer)->sort()->values()->toArray() : [];
                return $correctIds === $userIds;

            case 'true_false':
                $correctAnswer = $question->answers()->where('is_correct', true)->first();
                return $correctAnswer !== null && $correctAnswer->id == $userAnswer;

            case 'short_answer':
            case 'essay':
                // Nécessite correction manuelle — null = caller responsability
                return null;

            default:
                return false;
        }
    }

    /**
     * Calcule les points obtenus pour une réponse user.
     *
     * 0 quand la question requiert correction manuelle (le grader humain
     * setera les points via `QuizAttempt::manualGrade`).
     *
     * @param  mixed  $userAnswer
     */
    public function calculatePoints(QuizQuestion $question, $userAnswer): float
    {
        $isCorrect = $this->checkAnswer($question, $userAnswer);

        if ($isCorrect === null) {
            // Nécessite correction manuelle — 0 par défaut
            return 0;
        }

        return $isCorrect ? (float) $question->points : 0;
    }

    /**
     * Auto-correction de toutes les questions d'un attempt.
     *
     * Modifie l'attempt par référence (points_earned, points_possible,
     * score, passed, status). Le caller doit appeler `save()` après.
     *
     * Si au moins une question requiert une correction manuelle, le status
     * passe à `'submitted'` (en attente du grading manuel), pas `'graded'`.
     */
    public function gradeAttempt(QuizAttempt $attempt): void
    {
        $quiz = $attempt->quiz;
        $questions = $quiz->questions()->with('answers')->get();

        $pointsEarned = 0;
        $pointsPossible = 0;
        $requiresManualGrading = false;

        foreach ($questions as $question) {
            $pointsPossible += $question->points;

            $userAnswer = $attempt->answers[$question->id] ?? null;

            if ($question->requiresManualGrading()) {
                $requiresManualGrading = true;
                continue;
            }

            if ($userAnswer !== null) {
                $pointsEarned += $this->calculatePoints($question, $userAnswer);
            }
        }

        $attempt->points_earned = $pointsEarned;
        $attempt->points_possible = $pointsPossible;

        if (!$requiresManualGrading) {
            // Toutes les questions sont auto-corrigées
            $attempt->score = $pointsPossible > 0 ? ($pointsEarned / $pointsPossible) * 100 : 0;
            $attempt->passed = $attempt->score >= $quiz->passing_score;
            $attempt->status = 'graded';
        } else {
            // Nécessite correction manuelle
            $attempt->status = 'submitted';
        }
    }
}
