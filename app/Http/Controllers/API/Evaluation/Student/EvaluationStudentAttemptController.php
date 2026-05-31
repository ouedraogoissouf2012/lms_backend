<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Evaluation\Student;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\StartEvaluationRequest;
use App\Http\Requests\SubmitEvaluationRequest;
use App\Models\EvaluationSubmission;
use App\Services\Evaluation\EvaluationGradingService;
use App\Services\Evaluation\Student\EvaluationAttemptStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Mutations d'une tentative étudiant : démarrage, soumission, status temporel.
 * Extrait de `EvaluationStudentController` (split §5).
 *
 * Endpoints :
 *   - POST /api/evaluations/{id}/start
 *   - POST /api/evaluations/{id}/submit
 *   - GET  /api/evaluations/{id}/time-status
 */
final class EvaluationStudentAttemptController extends AuthenticatedController
{
    public function __construct(
        private readonly EvaluationAttemptStateService $attemptState,
        private readonly EvaluationGradingService $gradingService,
    ) {}

    public function startEvaluation(int $id, StartEvaluationRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->attemptState->startAttempt($id, $user);

        return match ($result['status']) {
            'not_found' => response()->json(['success' => false, 'message' => 'Évaluation non disponible'], 404),
            'no_questions' => response()->json([
                'success' => false,
                'message' => 'Cette évaluation n\'a pas encore de questions.',
            ], 422),
            'no_token' => response()->json([
                'success' => false,
                'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.',
            ], 401),
            'window_closed' => response()->json([
                'success' => false,
                'message' => $result['message'],
                'window' => $result['window'] ?? null,
            ], 403),
            'max_attempts' => response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 403),
            'ok' => response()->json([
                'success' => true,
                'message' => ($result['resumed'] ?? false)
                    ? 'Reprise de la tentative en cours'
                    : (($result['is_practice'] ?? false) ? 'Mode entraînement démarré' : 'Évaluation démarrée'),
                'data' => $result['submission'],
                'window' => $result['window'] ?? null,
                'is_practice' => $result['is_practice'] ?? false,
            ], ($result['resumed'] ?? false) ? 200 : 200),
            default => response()->json(['success' => false, 'message' => 'Erreur inconnue'], 500),
        };
    }

    public function submitEvaluation(int $id, SubmitEvaluationRequest $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            $submission = EvaluationSubmission::where('evaluation_id', $id)
                ->where('student_id', $user->id)
                ->where('status', 'en_cours')
                ->first();

            if (!$submission) {
                $submission = EvaluationSubmission::create([
                    'evaluation_id' => $id,
                    'student_id' => $user->id,
                    'klassci_etudiant_id' => $user->klassci_id,
                    'attempt' => 1,
                    'status' => 'en_cours',
                    'started_at' => now(),
                ]);
            }

            $submission->answers = $request->validated('answers');
            // TODO post-#176: $this->gradingService->submit($submission);
            // Sur cette branche fork avant #176, EvaluationSubmission::submit() existe encore.
            $submission->submit();

            return response()->json([
                'success' => true,
                'message' => 'Évaluation soumise avec succès',
                'data' => [
                    'submission' => $submission,
                    'score' => $submission->score,
                    'note_sur_20' => $submission->note_sur_20,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Erreur soumission évaluation', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erreur lors de la soumission'], 500);
        }
    }

    public function getTimeStatus(int $id, Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $data = $this->attemptState->getTimeStatus($id, $user);
            return response()->json(['success' => true, 'data' => $data]);
        } catch (RuntimeException $e) {
            // §1.2 — message fixé au site du catch, pas dérivé de getMessage()
            $isMissingEval = $e->getMessage() === 'Évaluation non trouvée';
            return response()->json([
                'success' => false,
                'message' => $isMissingEval ? 'Évaluation non trouvée' : 'Token KLASSCI non trouvé',
            ], $isMissingEval ? 404 : 401);
        } catch (\Exception $e) {
            Log::error('Erreur récupération état temporel', [
                'evaluation_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Impossible de récupérer l\'état temporel',
            ], 500);
        }
    }
}
