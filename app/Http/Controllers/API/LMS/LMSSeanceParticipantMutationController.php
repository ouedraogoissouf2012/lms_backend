<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\ValidateParticipantRequest;
use App\Services\Seances\Mutations\ParticipantValidationService;
use Illuminate\Http\JsonResponse;

/**
 * LMSSeanceParticipantMutationController — thin controller dédié à la
 * validation des participants d'une séance visio.
 *
 * Issu du split de `LMSSeancesMutationController` (602 lignes → 2 controllers
 * + 4 services SRP). Aucun changement comportemental.
 *
 * @see PRODUCTION_STANDARDS.md §5 — Controllers ≤200 lignes
 */
final class LMSSeanceParticipantMutationController extends AuthenticatedController
{
    public function __construct(
        private readonly ParticipantValidationService $participantValidation,
    ) {}

    /**
     * POST /api/lms/seances/{seanceId}/validate-participant
     */
    public function validateParticipant(int $seanceId, ValidateParticipantRequest $request): JsonResponse
    {
        $userId = (int) $request->validated('user_id');
        $result = $this->participantValidation->validate($seanceId, $userId);

        return response()->json($result['payload'], $result['status']);
    }
}
