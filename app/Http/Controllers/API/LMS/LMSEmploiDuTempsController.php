<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\CopierEmploiDuTempsRequest;
use App\Http\Requests\EmploiDuTempsRequest;
use App\Http\Requests\LignesEmploiDuTempsRequest;
use App\Services\EmploiDuTemps\CopieDEmploiDuTemps;
use App\Services\EmploiDuTemps\EmploisDuTemps;
use App\Services\EmploiDuTemps\GenerationDesSeances;
use App\Services\EmploiDuTemps\GrilleDeLEmploi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #1669 — les emplois du temps d'une école (gestion) : lister, créer, lire, modifier, supprimer ; la grille ; la
 * génération des séances ; les alertes ; la copie. La porte du catalogue local (dans {@see EmploisDuTemps}) refuse une
 * école KLASSCI (403 `catalogue_managed_by_klassci`). Les services ne dépendent d'aucune source par école : leur
 * injection au constructeur est sûre.
 */
final class LMSEmploiDuTempsController extends AuthenticatedController
{
    public function __construct(
        private readonly EmploisDuTemps $emplois,
        private readonly GrilleDeLEmploi $grille,
        private readonly GenerationDesSeances $generation,
        private readonly CopieDEmploiDuTemps $copie,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $classe = $request->query('classe_id');

        return $this->repondre(fn () => $this->successResponse($this->emplois->lister(
            $this->authenticatedUser($request),
            is_numeric($classe) ? (int) $classe : null,
        )));
    }

    public function store(EmploiDuTempsRequest $request): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse($this->emplois->presenter($this->emplois->creer(
            $this->authenticatedUser($request),
            ['classe_id' => $request->classeId()] + $request->champs(),
        )), 'Emploi du temps créé', 201));
    }

    public function show(Request $request, int $emploiId): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse($this->emplois->presenter($this->emplois->designer($emploiId, $this->authenticatedUser($request)))));
    }

    public function update(EmploiDuTempsRequest $request, int $emploiId): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse($this->emplois->presenter(
            $this->emplois->modifier($emploiId, $this->authenticatedUser($request), $request->champs()),
        )));
    }

    public function destroy(Request $request, int $emploiId): JsonResponse
    {
        return $this->repondre(function () use ($request, $emploiId): JsonResponse {
            $this->emplois->supprimer($emploiId, $this->authenticatedUser($request));

            return $this->successResponse(null, 'Emploi du temps supprimé');
        });
    }

    public function lignes(LignesEmploiDuTempsRequest $request, int $emploiId): JsonResponse
    {
        return $this->repondre(function () use ($request, $emploiId): JsonResponse {
            $acteur = $this->authenticatedUser($request);
            $retirees = $this->grille->remplacer($this->emplois->designer($emploiId, $acteur), $request->lignes());

            return $this->successResponse($this->emplois->presenter($this->emplois->designer($emploiId, $acteur)) + ['retirees' => $retirees]);
        });
    }

    public function generer(Request $request, int $emploiId): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse($this->generation->generer($this->emplois->designer($emploiId, $this->authenticatedUser($request)))));
    }

    public function alertes(Request $request): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse($this->emplois->alertes($this->authenticatedUser($request))));
    }

    public function copier(CopierEmploiDuTempsRequest $request, int $emploiId): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse($this->emplois->presenter($this->copie->copier(
            $emploiId,
            $this->authenticatedUser($request),
            $request->classeId(),
            $request->du(),
            $request->au(),
            $request->titre(),
        )), 'Emploi du temps copié', 201));
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
