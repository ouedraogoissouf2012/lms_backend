<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors;
use App\Http\Controllers\AuthenticatedController;
use App\Services\SeancesListQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * LMSSeancesListController — listing endpoints for séances of the current user.
 *
 * Extracted from the legacy `LMSSeancesQueryController` (922 lines, §5 violation)
 * during split-1.
 *
 * ## Cohesion (SRP)
 *
 * All three endpoints return a list of séances scoped to the authenticated user.
 * Detail endpoints (single séance) live in `LMSSeanceDetailsController`;
 * historical endpoints in `LMSSeancesHistoryController`.
 *
 * ## Endpoints
 *   - GET /api/lms/seances/upcoming     → upcomingSeances()
 *   - GET /api/lms/seances/my-teaching  → myTeachingSeances()  (role:enseignant,coordinateur)
 *   - GET /api/lms/seances/my-classes   → myClassesSeances()
 *
 * @see \App\Services\SeancesListQueryService
 */
final class LMSSeancesListController extends AuthenticatedController
{
    use RendersKlassciBackedErrors;

    public function __construct(
        private readonly SeancesListQueryService $seancesList,
    ) {}

    /**
     * GET /api/lms/seances/upcoming
     */
    public function upcomingSeances(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            // Convertir en int pour éviter erreur Carbon avec string
            $days = (int) $request->input('days', 30);
            $teacherId = $request->input('teacher_id');
            $teacherId = $teacherId !== null ? (int) $teacherId : null;
            $classeId = $request->input('classe_id');
            $classeId = $classeId !== null ? (int) $classeId : null;

            $payload = $this->seancesList->getUpcomingForUser($user, $days, $teacherId, $classeId);

            // NON migré vers successResponse() : cette réponse expose une clé sœur
            // `meta` à la racine. Le trait (DRY-only) OMET `meta` quand il est vide,
            // alors que cette enveloppe doit TOUJOURS émettre la clé — la migration
            // changerait la sortie sur le cas limite `meta = []`. Laissé inline.
            return response()->json([
                'success' => true,
                'data' => $payload['data'],
                'meta' => $payload['meta'],
            ]);

        } catch (RuntimeException $e) {
            // §1.2 — le STATUT de KLASSCI est relayé, jamais son corps de réponse :
            // le message vient d'une table fixe côté LMS.
            return $this->renderKlassciFailure($e);
        } catch (\Exception $e) {
            Log::error('Erreur récupération séances à venir', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // NON migré : enveloppe d'erreur portant une clé sœur `error` (singulier)
            // que le trait ne reproduit pas (errorResponse n'a qu'un `errors` pluriel).
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des séances',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/seances/my-teaching
     * Séances de l'enseignant connecté.
     */
    public function myTeachingSeances(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $seances = $this->seancesList->getMyTeachingForUser($user);

            return $this->successResponse($seances);

        } catch (RuntimeException $e) {
            // §1.2 — le STATUT de KLASSCI est relayé, jamais son corps de réponse :
            // le message vient d'une table fixe côté LMS.
            return $this->renderKlassciFailure($e);
        } catch (\Exception $e) {
            Log::error('Erreur récupération séances enseignant', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // NON migré : enveloppe d'erreur portant une clé sœur `error` (singulier)
            // que le trait ne reproduit pas (errorResponse n'a qu'un `errors` pluriel).
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des séances',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/seances/my-classes
     * Séances de l'étudiant connecté (sa classe).
     */
    public function myClassesSeances(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $result = $this->seancesList->getMyClassesForUser($user);

            // Cas particulier : étudiant sans classe résoluble
            if (isset($result['classe_missing']) && $result['classe_missing'] === true) {
                return $this->errorResponse('Aucune classe trouvée pour cet étudiant', 404);
            }

            // Cas particulier : étudiant avec classe mais aucune matière (200 + message)
            if (isset($result['empty_matieres']) && $result['empty_matieres'] === true) {
                return $this->successResponse([], 'Aucune matière trouvée pour cet étudiant');
            }

            return $this->successResponse($result);

        } catch (RuntimeException $e) {
            // §1.2 — le STATUT de KLASSCI est relayé, jamais son corps de réponse :
            // le message vient d'une table fixe côté LMS.
            return $this->renderKlassciFailure($e);
        } catch (\Exception $e) {
            Log::error('Erreur récupération séances étudiant', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // NON migré : enveloppe d'erreur portant une clé sœur `error` (singulier)
            // que le trait ne reproduit pas (errorResponse n'a qu'un `errors` pluriel).
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des séances',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }
}
