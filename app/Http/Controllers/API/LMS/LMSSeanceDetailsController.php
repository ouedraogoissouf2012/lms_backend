<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Services\SeanceDetailQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * LMSSeanceDetailsController — single-séance read endpoints.
 *
 * Extracted from the legacy `LMSSeancesQueryController` (922 lines, §5 violation)
 * during split-1.
 *
 * ## Cohesion (SRP)
 *
 * Both endpoints query a SPECIFIC séance by id, so they share the same
 * `SeanceDetailQueryService` dependency. Listing endpoints live in
 * `LMSSeancesListController`; historical endpoints in `LMSSeancesHistoryController`.
 *
 * ## Endpoints
 *   - GET /api/lms/seances/{seanceId}/details      → seanceDetails()
 *   - GET /api/lms/seances/{seanceId}/participants → seanceParticipants()
 *
 * @see \App\Services\SeanceDetailQueryService
 */
final class LMSSeanceDetailsController extends AuthenticatedController
{
    public function __construct(
        private readonly SeanceDetailQueryService $seanceDetailQuery,
    ) {}

    /**
     * GET /api/lms/seances/{id}/details
     * Détails complets d'une séance avec infos visio.
     */
    public function seanceDetails(int $seanceId, Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $data = $this->seanceDetailQuery->getSeanceDetailsArray($seanceId, $user);

            if ($data === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (RuntimeException $e) {
            // Thrown by SeanceDetailQueryService when user has no klassci_token.
            // §1.2 — message fixé au site du catch, pas dérivé de l'exception
            // (le thrower peut changer son message sans que le client soit reformaté).
            return response()->json([
                'success' => false,
                'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.',
            ], 401);
        } catch (\Exception $e) {
            Log::error('Erreur récupération détails séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des détails de la séance',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/seances/{id}/participants
     */
    public function seanceParticipants(int $seanceId, Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $data = $this->seanceDetailQuery->getSeanceParticipantsArray($seanceId, $user);

            if ($data === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.',
            ], 401);
        } catch (\Exception $e) {
            Log::error('Erreur récupération participants séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des participants',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }
}
