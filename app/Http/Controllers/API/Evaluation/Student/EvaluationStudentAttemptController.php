<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Evaluation\Student;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\StartEvaluationRequest;
use App\Http\Requests\SubmitEvaluationRequest;
use App\Models\Evaluation;
use App\Services\Evaluation\EvaluationGradingService;
use App\Services\Evaluation\Student\EvaluationAttemptOpener;
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
        private readonly EvaluationAttemptOpener $attemptOpener,
    ) {}

    public function startEvaluation(int $id, StartEvaluationRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->attemptState->startAttempt($id, $user);

        return match ($result['status']) {
            'not_found' => $this->errorResponse('Évaluation non disponible', 404),
            'no_questions' => $this->errorResponse('Cette évaluation n\'a pas encore de questions.', 422),
            'no_token' => $this->errorResponse('Token KLASSCI non trouvé. Veuillez vous reconnecter.', 401),
            // Même message que `GET /my-submission` : un étudiant non synchronisé
            // KLASSCI ne peut pas ouvrir de tentative (colonne NOT NULL) — refus
            // explicite plutôt que violation de contrainte en 500 (#540).
            'no_klassci_id' => $this->errorResponse('Utilisateur sans ID KLASSCI synchronisé', 401),
            // Course concurrente perdue sur `eval_sub_unique` sans tentative
            // reprenable : conflit métier, jamais 500 (#540).
            'conflict' => $this->errorResponse(
                'Une autre tentative vient d\'être enregistrée pour cette évaluation. Rechargez la page.',
                409,
            ),
            // Non migré vers errorResponse() : cette réponse expose une clé racine
            // `window` hors enveloppe que le trait ne reproduit pas. La déplacer
            // (sous `errors`) changerait le contrat client → conservé tel quel
            // (axe #1 « DRY-only », préservation de la sortie).
            'window_closed' => response()->json([
                'success' => false,
                'message' => $result['message'],
                'window' => $result['window'] ?? null,
            ], 403),
            'max_attempts' => $this->errorResponse($result['message'], 403),
            // #499 : fenêtre non vérifiable (KLASSCI indisponible) → 503 transitoire
            // (fail-closed), pas 403 : ce n'est pas un refus d'accès mais une
            // indisponibilité de dépendance ; le client doit réessayer.
            'window_check_failed' => $this->errorResponse(
                $result['message'] ?? "Service momentanément indisponible, veuillez réessayer.",
                503
            ),
            // Non migré vers successResponse() : cette réponse expose des clés
            // racine `window` + `is_practice` hors enveloppe que le trait ne
            // reproduit pas → conservé tel quel (axe #1 « DRY-only »).
            'ok' => response()->json([
                'success' => true,
                'message' => ($result['resumed'] ?? false)
                    ? 'Reprise de la tentative en cours'
                    : (($result['is_practice'] ?? false) ? 'Mode entraînement démarré' : 'Évaluation démarrée'),
                'data' => $result['submission'],
                'window' => $result['window'] ?? null,
                'is_practice' => $result['is_practice'] ?? false,
            ], ($result['resumed'] ?? false) ? 200 : 200),
            default => $this->errorResponse('Erreur inconnue', 500),
        };
    }

    /**
     * Soumet les réponses de l'étudiant.
     *
     * La soumission est ouverte via {@see EvaluationAttemptOpener}, le MÊME
     * point d'entrée que `POST /start`. Avant #540, ce controller cherchait la
     * tentative par `student_id` — que le démarrage ne renseignait jamais — puis
     * en recréait une avec `attempt = 1` : violation de `eval_sub_unique` et
     * **500 systématique** dès que l'étudiant avait démarré son évaluation.
     */
    public function submitEvaluation(int $id, SubmitEvaluationRequest $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            $evaluation = Evaluation::find($id);
            if (!$evaluation) {
                return $this->errorResponse('Évaluation non disponible', 404);
            }

            $opened = $this->attemptOpener->open($evaluation, $user, $evaluation->isTerminee());
            if ($opened['status'] !== 'ok') {
                return $this->mapOpeningFailure($opened);
            }

            $submission = $opened['submission'];
            $submission->answers = $request->validated('answers');
            $this->gradingService->submit($submission);

            return $this->successResponse([
                'submission' => $submission,
                'score' => $submission->score,
                'note_sur_20' => $submission->note_sur_20,
            ], 'Évaluation soumise avec succès', 201);
        } catch (\Exception $e) {
            Log::error('Erreur soumission évaluation', ['error' => $e->getMessage()]);
            return $this->errorResponse('Erreur lors de la soumission', 500);
        }
    }

    /**
     * Traduit un échec d'ouverture de soumission en réponse HTTP — mêmes codes
     * et mêmes messages que `POST /start`, pour que les deux endpoints refusent
     * de façon identique.
     *
     * @param  array{status: string, message?: string}  $opened
     */
    private function mapOpeningFailure(array $opened): JsonResponse
    {
        return match ($opened['status']) {
            'no_klassci_id' => $this->errorResponse('Utilisateur sans ID KLASSCI synchronisé', 401),
            'max_attempts' => $this->errorResponse(
                $opened['message'] ?? 'Nombre maximum de tentatives atteint',
                403,
            ),
            'conflict' => $this->errorResponse(
                'Une autre tentative vient d\'être enregistrée pour cette évaluation. Rechargez la page.',
                409,
            ),
            default => $this->errorResponse('Erreur lors de la soumission', 500),
        };
    }

    public function getTimeStatus(int $id, Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $data = $this->attemptState->getTimeStatus($id, $user);
            return $this->successResponse($data);
        } catch (RuntimeException $e) {
            // §1.2 — message fixé au site du catch, pas dérivé de getMessage()
            $isMissingEval = $e->getMessage() === 'Évaluation non trouvée';
            return $this->errorResponse(
                $isMissingEval ? 'Évaluation non trouvée' : 'Token KLASSCI non trouvé',
                $isMissingEval ? 404 : 401,
            );
        } catch (\Exception $e) {
            Log::error('Erreur récupération état temporel', [
                'evaluation_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Impossible de récupérer l\'état temporel', 500);
        }
    }
}
