<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors;
use App\Http\Controllers\AuthenticatedController;
use App\Services\Seances\SeancesListReader;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 * Même route, autre source (ADR-EI-01, lot 3d) : la source est reçue par la
 * MÉTHODE. Laravel garde le contrôleur sur l'objet Route : une source reçue par
 * le constructeur resterait celle de la première école servie. Le journal, sans
 * état et sans école, reste au constructeur.
 *
 * @see \App\Services\Seances\SeancesListReader
 */
final class LMSSeancesListController extends AuthenticatedController
{
    use RendersKlassciBackedErrors;

    public function __construct(
        private readonly CaughtExceptionLog $journal,
    ) {}

    /**
     * GET /api/lms/seances/upcoming
     */
    public function upcomingSeances(Request $request, SeancesListReader $seances): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            // Convertir en int pour éviter erreur Carbon avec string
            $days = (int) $request->input('days', 30);
            $teacherId = $request->input('teacher_id');
            $teacherId = $teacherId !== null ? (int) $teacherId : null;
            $classeId = $request->input('classe_id');
            $classeId = $classeId !== null ? (int) $classeId : null;

            $payload = $seances->getUpcomingForUser($user, $days, $teacherId, $classeId);

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
            $this->journal->error('Erreur récupération séances à venir', $e);

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
    public function myTeachingSeances(Request $request, SeancesListReader $seances): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            return $this->successResponse($seances->getMyTeachingForUser($user));

        } catch (RuntimeException $e) {
            // §1.2 — le STATUT de KLASSCI est relayé, jamais son corps de réponse :
            // le message vient d'une table fixe côté LMS.
            return $this->renderKlassciFailure($e);
        } catch (\Exception $e) {
            $this->journal->error('Erreur récupération séances enseignant', $e);

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
    public function myClassesSeances(Request $request, SeancesListReader $seances): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $result = $seances->getMyClassesForUser($user);

            // Cas particulier : étudiant sans classe résoluble. Le motif distingue ce
            // 404 d'un 404 relayé de KLASSCI, que la même route rend aussi.
            if (isset($result['classe_missing']) && $result['classe_missing'] === true) {
                return $this->errorResponse('Aucune classe trouvée pour cet étudiant', 404, reason: 'student_without_active_class');
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
            $this->journal->error('Erreur récupération séances étudiant', $e);

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
