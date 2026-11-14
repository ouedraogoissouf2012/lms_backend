<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Evaluation\Student;

use App\Http\Controllers\AuthenticatedController;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\Student\ResultatsVisibles;
use App\Support\Logging\CaughtExceptionLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lecture de la dernière soumission étudiant pour une évaluation, avec
 * masquage des bonnes réponses tant que le délai de correction
 * (7 jours par défaut) n'est pas écoulé.
 *
 * Extrait de `EvaluationStudentController::getMySubmission` (split §5).
 *
 * Endpoint : GET /api/evaluations/{id}/my-submission
 */
final class EvaluationStudentSubmissionController extends AuthenticatedController
{
    /** Délai (jours) avant que les corrections soient visibles à l'étudiant. */
    private const CORRECTION_DELAY_DAYS = 7;

    public function __construct(
        private readonly CaughtExceptionLog $journal,
    ) {}

    public function getMySubmission(int $id, Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            // D72, E2a : la copie appartient à son auteur par `student_id`
            // (EvaluationSubmission::ownedBy) ; l'identité KLASSCI n'est plus
            // exigée. Sans copie à soi : 404.

            $submission = $this->ownLatestCopy($id, $user);
            $evaluation = $submission?->evaluation;
            if (! $submission instanceof EvaluationSubmission || ! $evaluation instanceof Evaluation) {
                return $this->errorResponse('Aucune soumission trouvée pour cette évaluation', 404);
            }

            $submittedAt = $submission->submitted_at ? Carbon::parse($submission->submitted_at) : null;
            // `now()->diffInDays($passe)` rend un écart SIGNÉ depuis Carbon 3 :
            // -30 pour une copie rendue il y a trente jours. La comparaison
            // `>= 7` ne pouvait donc JAMAIS être vraie, et l'élève n'a jamais
            // vu sa correction. Comparer deux instants ne dépend d'aucun signe.
            $correctionAvailable = $submittedAt !== null
                && now()->greaterThanOrEqualTo($submittedAt->copy()->addDays(self::CORRECTION_DELAY_DAYS));
            $correctionAvailableAt = $submittedAt
                ? $submittedAt->copy()->addDays(self::CORRECTION_DELAY_DAYS)->toIso8601String()
                : null;

            $questions = $evaluation->questions()->get();
            if ($evaluation->shuffle_questions) {
                $questions = $questions->shuffle()->values();
            }
            // Le corrigé est masqué par défaut sur le modèle ({@see
            // \App\Models\EvaluationQuestion}). Ce chemin — la copie de l'élève
            // lui-même, après le délai — est le seul à le révéler, et il le
            // fait explicitement. L'ancien `unset` faisait l'inverse : il
            // retirait ce que tous les autres chemins laissaient passer.
            $questionsData = $questions->map(static function ($question) use ($correctionAvailable) {
                if ($correctionAvailable) {
                    $question = (clone $question)->makeVisible(['correct_answers', 'explanation']);
                }

                return $question->toArray();
            });

            $score = $submission->score;
            $note = $submission->note_sur_20;
            // La règle « résultats visibles » (C25), partagée avec la liste et
            // la remise.
            if (! ResultatsVisibles::pour($evaluation)) {
                $score = null;
                $note = null;
            }

            return $this->successResponse([
                'id' => $submission->id,
                'evaluation_id' => $submission->evaluation_id,
                'attempt' => $submission->attempt,
                'status' => $submission->status,
                'started_at' => $submission->started_at,
                'submitted_at' => $submission->submitted_at,
                'score' => $score,
                'note_sur_20' => $note,
                'feedback' => $submission->feedback,
                'questions' => $questionsData,
                'answers' => $submission->answers ?? [],
                'correction_available' => $correctionAvailable,
                'correction_available_at' => $correctionAvailableAt,
                'correction_delay_days' => self::CORRECTION_DELAY_DAYS,
                'evaluation' => [
                    'id' => $evaluation->id,
                    'titre' => $evaluation->titre,
                    'bareme' => $evaluation->bareme,
                    'coefficient' => $evaluation->coefficient,
                ],
            ]);
        } catch (\Exception $e) {
            $this->journal->error('Erreur récupération soumission étudiant', $e, [
                'evaluation_id' => $id,
            ]);
            return $this->errorResponse('Erreur lors de la récupération de la soumission', 500);
        }
    }

    /**
     * La dernière copie de CET élève sur cette évaluation, dans SON établissement.
     *
     * Le droit de lire sa copie tient à la PROPRIÉTÉ de la copie, pas à sa
     * classe actuelle : l'écran des notes (my-grades) propose aussi les copies
     * des classes passées. Le démarrage d'une copie passe désormais par la
     * portée de l'élève (LearnerEvaluationScope) ; les copies plus anciennes
     * restent lisibles par leur auteur.
     *
     * La copie est celle de l'élève DANS SON établissement (`ownedBy` : sans
     * établissement, rien). L'évaluation est cherchée dans ce même
     * établissement, explicitement, sans s'en remettre au scope global, ouvert
     * quand aucun tenant n'est posé. Une évaluation absente, d'une autre école
     * ou sans copie de l'élève rendent le même 404.
     */
    private function ownLatestCopy(int $evaluationId, User $user): ?EvaluationSubmission
    {
        return EvaluationSubmission::query()
            ->where('evaluation_id', $evaluationId)
            ->ownedBy($user)
            ->whereIn('evaluation_id', Evaluation::query()->select('id')->where('institution_id', $user->institution_id))
            ->with('evaluation')
            ->orderBy('attempt', 'desc')
            ->first();
    }
}
