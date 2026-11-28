<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\Student\RemiseDeCopie;

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
    public function __construct(private readonly QuestionsDeLaCopie $questionsDeLaCopie) {}

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
        if (! $question->correct_answers) {
            return false;
        }

        if ($question->type === 'qcm' || $question->type === 'vrai_faux') {
            return in_array($answer, $question->correct_answers);
        }

        if ($question->type === 'qcm_multiple') {
            if (! is_array($answer)) {
                return false;
            }
            sort($answer);
            $correctAnswers = $question->correct_answers;
            sort($correctAnswers);

            return $answer === $correctAnswers;
        }

        if ($question->type === 'reponse_courte') {
            // Une réponse courte est scalaire. Un payload mal formé (tableau, null,
            // objet) ne peut pas être correct — on le rejette SANS caster, ce qui
            // évitait auparavant un « Array to string conversion » → 500 (#564).
            if (! is_scalar($answer)) {
                return false;
            }
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
     * Indique si une question nécessite une correction MANUELLE (pas d'auto-grading).
     *
     * Seule la `dissertation` n'a aucune règle de correction automatique
     * (contrairement à qcm / qcm_multiple / vrai_faux / reponse_courte, tous
     * auto-corrigeables). Miroir du concept quiz `QuizQuestion::requiresManualGrading`.
     *
     * #588 : la note n'est finale qu'après `manualGrade()` (statut `corrige`).
     */
    public function requiresManualGrading(EvaluationQuestion $question): bool
    {
        return $question->type === 'dissertation';
    }

    /**
     * L'évaluation contient-elle au moins une question dont la note automatique
     * n'est pas le dernier mot ?
     *
     * La question était posée en DEUX exemplaires privés identiques — dans
     * `EvaluationKlassciSyncController` et `EvaluationScoreRecomputationService`,
     * tous deux délégant déjà la règle « quel type est manuel » à la méthode
     * ci-dessus. Seule l'agrégation restait recopiée. Un troisième appelant
     * (« l'élève voit-il cette note ? ») en aurait fait une troisième copie :
     * elle monte donc ici, auprès de la primitive dont elle dérive.
     */
    /**
     * #1702 (D2) : la COPIE attend-elle une correction manuelle ? Sur ses seules questions
     * posées ({@see QuestionsDeLaCopie}) : une dissertation non posée ne rend pas sa note
     * provisoire.
     */
    public function copieRequiresManualGrading(EvaluationSubmission $copie): bool
    {
        return $this->questionsDeLaCopie->de($copie)->contains(
            fn (EvaluationQuestion $question): bool => $this->requiresManualGrading($question)
        );
    }

    public function evaluationRequiresManualGrading(Evaluation $evaluation): bool
    {
        return $evaluation->questions->contains(
            fn (EvaluationQuestion $question): bool => $this->requiresManualGrading($question)
        );
    }

    /**
     * Calcule le score d'une soumission en itérant sur les questions.
     *
     * Calcul PUR, en mémoire : il pose `score` (points bruts) et `note_sur_20`
     * (pourcentage × barème de l'évaluation) sur le modèle, sans rien écrire en base.
     * L'appelant persiste, chacun à sa façon :
     *
     * - une REMISE passe par l'écriture conditionnelle unique de
     *   {@see RemiseDeCopie} (`UPDATE … WHERE status =
     *   'en_cours'`, C39). Un `save()` sans condition sur une copie en cours
     *   recréerait la double notation ;
     * - la correction manuelle ({@see manualGrade()}) et le recalcul
     *   ({@see EvaluationScoreRecomputationService}) portent sur des copies déjà
     *   remises, et font leur `save()`.
     */
    public function calculateScore(EvaluationSubmission $submission): void
    {
        $evaluation = $submission->evaluation()->with('questions')->first();
        // #1702 (D2) : la note porte sur les questions POSÉES de la copie, pas sur toute l'évaluation.

        $totalPoints = 0;
        $earnedPoints = 0;

        $manualPoints = is_array($submission->manual_points) ? $submission->manual_points : [];

        foreach ($this->questionsDeLaCopie->de($submission, $evaluation) as $question) {
            $totalPoints += $question->points;

            if ($this->requiresManualGrading($question)) {
                $earnedPoints += $this->manualPointsFor($question, $manualPoints);

                continue;
            }

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

    /**
     * #1678 : la note décidée par la gestion au terme d'une réclamation. Elle remplace la note qui
     * compte (`note_sur_20`) ; la copie est à renvoyer à KLASSCI (`synced_to_klassci` faux), dont
     * le renvoi reste le geste de l'enseignant. Par le modèle (`saveOrFail`) : l'audit, et
     * l'historique des notes (#1698), la consignent.
     */
    public function fixerLaNote(EvaluationSubmission $copie, float $note): void
    {
        $copie->note_sur_20 = round($note, 2);
        $copie->synced_to_klassci = false;
        $copie->saveOrFail();
    }

    /**
     * #588 — note les dissertations, recalcule note_sur_20, passe en `corrige`.
     *
     * @param  array<int|string, float|int|string>  $manualPoints
     */
    public function manualGrade(
        EvaluationSubmission $submission,
        array $manualPoints,
        User $grader,
        ?string $feedback,
    ): void {
        $submission->manual_points = $manualPoints;
        $this->calculateScore($submission);
        $submission->status = 'corrige';
        $submission->graded_by = max(0, $grader->id);
        $submission->graded_at = now();
        if ($feedback !== null) {
            $submission->feedback = $feedback;
        }
        $submission->save();
    }

    /**
     * @param  array<int|string, mixed>  $manualPoints
     */
    private function manualPointsFor(EvaluationQuestion $question, array $manualPoints): float
    {
        $raw = $manualPoints[$question->id] ?? $manualPoints[(string) $question->id] ?? null;
        if (! is_numeric($raw)) {
            return 0.0;
        }

        $points = (float) $raw;
        $max = (float) $question->points;

        return max(0.0, min($points, $max));
    }
}
