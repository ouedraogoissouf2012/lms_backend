<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\EvenementCalendrierRequest;
use App\Http\Requests\LectureDuCalendrierRequest;
use App\Services\Calendrier\CalendrierDeLEcole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #1671 — le calendrier de l'année : tout membre de l'école lit (dans sa portée), la gestion écrit (middleware
 * `gestion` sur les écritures). Les deux volets, sans porte du catalogue : KLASSCI n'expose aucun calendrier. Le
 * service ne dépend d'aucune source mémorisée par école : son injection au constructeur est sûre.
 */
final class LMSCalendrierController extends AuthenticatedController
{
    public function __construct(private readonly CalendrierDeLEcole $calendrier) {}

    public function index(LectureDuCalendrierRequest $request): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse($this->calendrier->lire($this->authenticatedUser($request), $request->du(), $request->au())));
    }

    public function store(EvenementCalendrierRequest $request): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse(
            $this->calendrier->presenter($this->calendrier->creer($this->authenticatedUser($request), $request->champs())),
            'Événement publié',
            201,
        ));
    }

    public function update(EvenementCalendrierRequest $request, int $evenementId): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse(
            $this->calendrier->presenter($this->calendrier->modifier($evenementId, $this->authenticatedUser($request), $request->champs())),
        ));
    }

    public function destroy(Request $request, int $evenementId): JsonResponse
    {
        return $this->repondre(function () use ($request, $evenementId): JsonResponse {
            $this->calendrier->supprimer($evenementId, $this->authenticatedUser($request));

            return $this->successResponse(null, 'Événement supprimé');
        });
    }

    /** @param  callable(): JsonResponse  $action */
    private function repondre(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }
}
