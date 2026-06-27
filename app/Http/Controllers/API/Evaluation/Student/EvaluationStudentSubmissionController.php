<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Evaluation\Student;

use App\Http\Controllers\AuthenticatedController;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

    public function getMySubmission(int $id, Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            if (!$user->klassci_id) {
                return $this->errorResponse('Utilisateur sans ID KLASSCI synchronisé', 401);
            }

            $evaluation = Evaluation::find($id);
            if (!$evaluation) {
                return $this->errorResponse('Évaluation non trouvée', 404);
            }

            $submission = EvaluationSubmission::where('evaluation_id', $id)
                ->where('klassci_etudiant_id', $user->klassci_id)
                ->orderBy('attempt', 'desc')
                ->first();

            if (!$submission) {
                return $this->errorResponse('Aucune soumission trouvée pour cette évaluation', 404);
            }

            $submittedAt = $submission->submitted_at ? Carbon::parse($submission->submitted_at) : null;
            $correctionAvailable = $submittedAt && now()->diffInDays($submittedAt) >= self::CORRECTION_DELAY_DAYS;
            $correctionAvailableAt = $submittedAt
                ? $submittedAt->copy()->addDays(self::CORRECTION_DELAY_DAYS)->toIso8601String()
                : null;

            $questionsData = $evaluation->questions()->get()->map(function ($question) use ($correctionAvailable) {
                $q = $question->toArray();
                if (!$correctionAvailable) {
                    unset($q['correct_answers'], $q['explanation']);
                }
                return $q;
            });

            return $this->successResponse([
                'id' => $submission->id,
                'evaluation_id' => $submission->evaluation_id,
                'attempt' => $submission->attempt,
                'status' => $submission->status,
                'started_at' => $submission->started_at,
                'submitted_at' => $submission->submitted_at,
                'score' => $submission->score,
                'note_sur_20' => $submission->note_sur_20,
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
            Log::error('Erreur récupération soumission étudiant', [
                'evaluation_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Erreur lors de la récupération de la soumission', 500);
        }
    }
}
