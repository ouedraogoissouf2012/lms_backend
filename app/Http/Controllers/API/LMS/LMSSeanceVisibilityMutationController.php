<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\DeleteLocalSeanceRequest;
use App\Http\Requests\DeleteSeanceRequest;
use App\Http\Requests\HideSeanceRequest;
use App\Http\Requests\ToggleVisioSeanceRequest;
use App\Http\Requests\UnhideSeanceRequest;
use App\Services\Seances\LocalSeanceDesignee;
use App\Services\Seances\Mutations\SeanceDeleteService;
use App\Services\Seances\Mutations\SeanceHideService;
use App\Services\Seances\SeanceDesignee;
use App\Services\Visio\Lifecycle\VisioToggleSource;
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
    ) {}

    /**
     * POST /api/lms/seances/{seanceId}/toggle-visio
     */
    public function toggleVisioSeance(int $seanceId, ToggleVisioSeanceRequest $request, VisioToggleSource $bascule): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $enabled = (bool) $request->validated('enabled');
        $visioType = $request->validated('visio_type');

        $result = $bascule->toggle(
            $seanceId,
            $enabled,
            is_string($visioType) ? $visioType : null,
            $user,
        );

        return $this->relayResponse($result);
    }

    /**
     * POST /api/lms/seances/{seanceId}/hide
     */
    public function hideSeance(int $seanceId, HideSeanceRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->seanceHide->hide($seanceId, $user);

        return $this->relayResponse($result);
    }

    /**
     * POST /api/lms/seances/{seanceId}/unhide
     */
    public function unhideSeance(int $seanceId, UnhideSeanceRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->seanceHide->unhide($seanceId, $user);

        return $this->relayResponse($result);
    }

    /**
     * DELETE /api/lms/seances/{seanceId}
     *
     * La séance est désignée dans l'espace de l'école (#933) ; la source est
     * reçue par la MÉTHODE : Laravel garde le contrôleur sur l'objet Route.
     */
    public function deleteSeance(DeleteSeanceRequest $request, int $seanceId, SeanceDesignee $designee): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->seanceDelete->delete($seanceId, $user, $designee);

        return $this->relayResponse($result);
    }

    /**
     * DELETE /api/lms/seances/local/{seanceId}
     *
     * La porte de l'espace local (#1154, ADR-1154-01) : la séance de son id local,
     * dans l'école du compte, dans les deux types d'école.
     */
    public function deleteLocalSeance(DeleteLocalSeanceRequest $request, int $seanceId, LocalSeanceDesignee $designee): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->seanceDelete->delete($seanceId, $user, $designee);

        return $this->relayResponse($result);
    }
}
