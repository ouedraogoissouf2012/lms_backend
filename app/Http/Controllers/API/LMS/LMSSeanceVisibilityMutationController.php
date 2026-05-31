<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\DeleteSeanceRequest;
use App\Http\Requests\HideSeanceRequest;
use App\Http\Requests\ToggleVisioSeanceRequest;
use App\Http\Requests\UnhideSeanceRequest;
use App\Services\Seances\Mutations\SeanceDeleteService;
use App\Services\Seances\Mutations\SeanceHideService;
use App\Services\Seances\Mutations\VisioToggleService;
use Illuminate\Http\JsonResponse;

/**
 * LMSSeanceVisibilityMutationController — thin controller regroupant les
 * mutations qui affectent la visibilité ou le cycle de vie d'une séance :
 *
 *   - `hideSeance` / `unhideSeance` : masquage personnel (étudiant)
 *   - `deleteSeance` : soft delete (enseignant/coordinateur/superAdmin)
 *   - `toggleVisioSeance` : activer/désactiver la visio (coordinateur/superAdmin)
 *
 * Issu du split de `LMSSeancesMutationController` (602 lignes → 2 controllers
 * + 4 services SRP). Aucun changement comportemental.
 *
 * @see PRODUCTION_STANDARDS.md §5 — Controllers ≤200 lignes
 */
final class LMSSeanceVisibilityMutationController extends AuthenticatedController
{
    public function __construct(
        private readonly SeanceHideService $seanceHide,
        private readonly SeanceDeleteService $seanceDelete,
        private readonly VisioToggleService $visioToggle,
    ) {}

    /**
     * POST /api/lms/seances/{seanceId}/toggle-visio
     */
    public function toggleVisioSeance(int $seanceId, ToggleVisioSeanceRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $enabled = (bool) $request->validated('enabled');
        $visioType = $request->validated('visio_type');

        $result = $this->visioToggle->toggle(
            $seanceId,
            $enabled,
            is_string($visioType) ? $visioType : null,
            $user,
        );

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * POST /api/lms/seances/{seanceId}/hide
     */
    public function hideSeance(int $seanceId, HideSeanceRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->seanceHide->hide($seanceId, $user);

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * POST /api/lms/seances/{seanceId}/unhide
     */
    public function unhideSeance(int $seanceId, UnhideSeanceRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->seanceHide->unhide($seanceId, $user);

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * DELETE /api/lms/seances/{seanceId}
     */
    public function deleteSeance(DeleteSeanceRequest $request, int $seanceId): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->seanceDelete->delete($seanceId, $user);

        return response()->json($result['payload'], $result['status']);
    }
}
