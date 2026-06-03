<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\ActivateVisioRequest;
use App\Http\Requests\DeactivateVisioRequest;
use App\Http\Requests\EndVisioRequest;
use App\Http\Requests\StartVisioRequest;
use App\Services\Visio\Lifecycle\VisioActivationService;
use App\Services\Visio\Lifecycle\VisioSessionService;
use Illuminate\Http\JsonResponse;

/**
 * LMSVisioLifecycleController — thin controller (§5 ≤200 lignes).
 *
 * Découpe issue du split du controller 434 lignes → controller thin
 * + 2 services SRP dans `App\Services\Visio\Lifecycle\`. Les 4 endpoints
 * gèrent le cycle de vie d'une visio côté "host" (enseignant/coordinateur)
 * et restent groupés ici pour la cohésion du domaine — chaque méthode
 * délègue à son service dédié.
 *
 * Découpage SRP par machine d'état :
 *  - {@see VisioActivationService} : configuration (activate/deactivate)
 *  - {@see VisioSessionService}    : session BBB live (start/end)
 *
 * IMPORTANT : la classe et les noms de méthodes (`activateVisio`,
 * `deactivateVisio`, `startVisio`, `endVisio`) sont fixés par
 * `routes/api.php` et `tests/Feature/LMS/Visio/VisioRoutingTest.php`.
 * Ne pas renommer sans mettre à jour ces deux fichiers.
 *
 * @see PRODUCTION_STANDARDS.md §5    — Controllers ≤200 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict (constructor injection)
 */
final class LMSVisioLifecycleController extends AuthenticatedController
{
    public function __construct(
        private readonly VisioActivationService $activationService,
        private readonly VisioSessionService $sessionService,
    ) {}

    /**
     * POST /api/lms/seances/{seanceId}/activate-visio
     * Active la visioconférence pour une séance (enseignant/coordinateur).
     */
    public function activateVisio(int $seanceId, ActivateVisioRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->activationService->activate(
            $seanceId,
            $user,
            $request->validated(),
        );

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * POST /api/lms/seances/{seanceId}/deactivate-visio
     * Désactive la visioconférence pour une séance (enseignant uniquement).
     */
    public function deactivateVisio(int $seanceId, DeactivateVisioRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->activationService->deactivate($seanceId, $user);

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * POST /api/lms/seances/{seanceId}/start-visio
     * Démarre la session live de visioconférence (enseignant/coordinateur).
     */
    public function startVisio(int $seanceId, StartVisioRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->sessionService->start(
            $seanceId,
            $user,
            $request->validated(),
        );

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * POST /api/lms/seances/{seanceId}/end-visio
     * Termine la session live de visioconférence (enseignant/coordinateur).
     */
    public function endVisio(int $seanceId, EndVisioRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->sessionService->end($seanceId, $user);

        return response()->json($result['payload'], $result['status']);
    }
}
