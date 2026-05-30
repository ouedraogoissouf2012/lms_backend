<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;

/**
 * Logique de correction automatique des évaluations extraite des modèles
 * Evaluation/EvaluationQuestion/EvaluationSubmission vers un service dédié
 * (PERF-04 batch 2 — pendant Quiz/QuizGradingService).
 *
 * ## Pourquoi extraire
 *
 * `EvaluationQuestion::isCorrectAnswer()` (35 lignes) branchait sur le type
 * de question (qcm / vrai_faux / qcm_multiple / reponse_courte) pour décider
 * de la correction. `EvaluationSubmission::calculateScore()` (25 lignes)
 * itérait sur les questions, accumulait points, et calculait note_sur_20
 * à partir du barème de l'évaluation. Logique métier qui n'a pas sa place
 * dans des modèles Eloquent (§1.1 manifest : « Models = state + relations
 * + scopes »).
 *
 * ## Comportement
 *
 * Aucun changement runtime — logique déplacée verbatim. Les modèles
 * conservent des thin wrappers délégant au service pour préserver les
 * call sites internes (`EvaluationSubmission::submit` ligne 93 →
 * `$this->calculateScore()`).
 *
 * ## DI strict (§1.6 D)
 *
 * Service sans dépendance constructeur — pure logique sur les models passés
 * en argument. Pas de Facade.
 *
 * @see app/Models/EvaluationQuestion.php::isCorrectAnswer (thin wrapper)
 * @see app/Models/EvaluationSubmission.php::calculateScore (thin wrapper)
 */
class EvaluationGradingService
{
    /**
     * Vérifie si la réponse étudiant à une question est correcte.
     *
     * Types gérés :
     *   - `qcm` / `vrai_faux` → match in_array sur la réponse unique
     *   - `qcm_multiple` → exact set match après tri
     *   - `reponse_courte` → comparaison case/trim insensible
     *
     * Retourne `false` si correct_answers est vide/null ou type inconnu.
     *
     * @param  mixed  $answer  Réponse de l'étudiant (string, int ou array selon type).
     */
    public function isCorrectAnswer(EvaluationQuestion $question, $answer): bool
    {
        if (!$question->correct_answers) {
            return false;
        }

        if ($question->type === 'qcm' || $question->type === 'vrai_faux') {
            return in_array($answer, $question->correct_answers);
        }

        if ($question->type === 'qcm_multiple') {
            if (!is_array($answer)) {
                return false;
            }
            sort($answer);
            $correctAnswers = $question->correct_answers;
            sort($correctAnswers);
            return $answer === $correctAnswers;
        }

        if ($question->type === 'reponse_courte') {
            $normalizedAnswer = strtolower(trim((string) $answer));
            foreach ($question->correct_answers as $correct) {
                if (strtolower(trim((string) $correct)) === $normalizedAnswer) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    /**
     * Calcule le score d'une soumission en itérant sur les questions.
     *
     * Modifie la submission par référence (score = points bruts, note_sur_20
     * = pourcentage × barème de l'évaluation). Le caller doit appeler `save()`
     * après.
     */
    public function calculateScore(EvaluationSubmission $submission): void
    {
        $evaluation = $submission->evaluation()->with('questions')->first();

        $totalPoints = 0;
        $earnedPoints = 0;

        foreach ($evaluation->questions as $question) {
            $totalPoints += $question->points;

            // Vérifier si l'étudiant a répondu
            if (isset($submission->answers[$question->id])) {
                $studentAnswer = $submission->answers[$question->id];

                if ($this->isCorrectAnswer($question, $studentAnswer)) {
                    $earnedPoints += $question->points;
                }
            }
        }

        $submission->score = $earnedPoints;
        $percentage = $totalPoints > 0 ? ($earnedPoints / $totalPoints) : 0;
        $submission->note_sur_20 = round($percentage * $evaluation->bareme, 2);
    }
}
