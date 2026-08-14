<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\SeancesHistoryRequest;
use App\Services\SeancesHistoryQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * LMSSeancesHistoryController — historical séances listing endpoint.
 *
 * Extracted from the legacy `LMSSeancesQueryController` (922 lines, §5 violation)
 * during split-1.
 *
 * ## Cohesion (SRP)
 *
 * Single-endpoint controller for the historical séances (séances that had a
 * visio started). Detail endpoints live in `LMSSeanceDetailsController`;
 * listing endpoints in `LMSSeancesListController`.
 *
 * ## Endpoint
 *   - GET /api/lms/seances/history → getSeancesHistory()
 *     (role:enseignant,coordinateur,superAdmin)
 *
 * @see \App\Services\SeancesHistoryQueryService
 */
final class LMSSeancesHistoryController extends AuthenticatedController
{
    public function __construct(
        private readonly SeancesHistoryQueryService $seancesHistory,
    ) {}

    /**
     * GET /api/lms/seances/history
     * Historique des présences (accessible même si séance archivée).
     */
    public function getSeancesHistory(SeancesHistoryRequest $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            $filters = [
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'search' => $request->input('search'),
                'per_page' => (int) $request->input('per_page', 50),
            ];

            $payload = $this->seancesHistory->getHistoryForUser($user, $filters);

            // NON migré vers successResponse() : cette réponse expose une clé sœur
            // `pagination` à la racine. Le trait (DRY-only) n'a aucun paramètre
            // `pagination` ; son paramètre `meta` renommerait la clé en `meta` →
            // sortie NON identique pour le client. Laissé inline.
            return response()->json([
                'success' => true,
                'data' => $payload['data'],
                'pagination' => $payload['pagination'],
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération historique séances', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // NON migré : enveloppe d'erreur portant une clé sœur `error` (singulier)
            // que le trait ne reproduit pas (errorResponse n'a qu'un `errors` pluriel).
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'historique des séances',
                'error' => 'Une erreur est survenue.',
            ], 500);
        }
    }
}
