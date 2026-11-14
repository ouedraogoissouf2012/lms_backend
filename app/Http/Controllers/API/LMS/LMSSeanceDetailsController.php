<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors;
use App\Http\Controllers\AuthenticatedController;
use App\Services\SeanceDetailQueryService;
use App\Services\Seances\SeanceDetailReader;
use App\Services\Seances\SeanceParticipantsReader;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * LMSSeanceDetailsController — single-séance read endpoints.
 *
 * Extracted from the legacy `LMSSeancesQueryController` (922 lines, §5 violation)
 * during split-1.
 *
 * ## Cohesion (SRP)
 *
 * Both endpoints query a SPECIFIC séance by id, each through the port of the
 * institution's source (ADR-EI-01), received by the METHOD. Listing endpoints live in
 * `LMSSeancesListController`; historical endpoints in `LMSSeancesHistoryController`.
 *
 * ## Endpoints
 *   - GET /api/lms/seances/{seanceId}/details      → seanceDetails()
 *   - GET /api/lms/seances/{seanceId}/participants → seanceParticipants()
 *
 * @see SeanceDetailQueryService
 */
final class LMSSeanceDetailsController extends AuthenticatedController
{
    use RendersKlassciBackedErrors;

    public function __construct(
        private readonly CaughtExceptionLog $journal,
    ) {}

    /**
     * GET /api/lms/seances/{id}/details
     * Détails complets d'une séance avec infos visio.
     */
    public function seanceDetails(int $seanceId, Request $request, SeanceDetailReader $detail): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            // Même route, autre source (ADR-EI-01) : reçue par la MÉTHODE (règle 3).
            $lu = $detail->detailFor($seanceId, $user);

            if ($lu === null) {
                return $this->errorResponse('Séance non trouvée', 404);
            }

            return $this->successResponse($lu['data'], meta: $lu['meta']);

        } catch (RuntimeException $e) {
            return $this->renderKlassciFailure($e);
        } catch (\Exception $e) {
            $this->journal->error('Erreur récupération détails séance', $e, [
                'seance_id' => $seanceId,
            ]);

            // NON migré : enveloppe d'erreur portant une clé sœur `error` (singulier)
            // que le trait ne reproduit pas (errorResponse n'a qu'un `errors` pluriel).
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des détails de la séance',
                'error' => 'Une erreur est survenue.',
            ], 500);
        }
    }

    /**
     * GET /api/lms/seances/{id}/participants
     */
    public function seanceParticipants(int $seanceId, Request $request, SeanceParticipantsReader $participants): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            // La source de l'établissement (ADR-EI-01), reçue par la MÉTHODE : en mode
            // autonome, les participants se lisent sans jeton KLASSCI.
            $data = $participants->getSeanceParticipantsArray($seanceId, $user);

            if ($data === null) {
                return $this->errorResponse('Séance non trouvée', 404);
            }

            return $this->successResponse($data);

        } catch (RuntimeException $e) {
            return $this->renderKlassciFailure($e);
        } catch (\Exception $e) {
            $this->journal->error('Erreur récupération participants séance', $e, [
                'seance_id' => $seanceId,
            ]);

            // NON migré : enveloppe d'erreur portant une clé sœur `error` (singulier)
            // que le trait ne reproduit pas (errorResponse n'a qu'un `errors` pluriel).
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des participants',
                'error' => 'Une erreur est survenue.',
            ], 500);
        }
    }
}
