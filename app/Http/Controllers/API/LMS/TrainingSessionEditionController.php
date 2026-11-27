<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Presenters\PeriodeEnVue;
use App\Http\Requests\UpdateTrainingSessionRequest;
use App\Services\Session\EditionDeLaPeriode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Modifier une Période, supprimer un brouillon, désigner la période en cours (#1651).
 *
 * Préoccupations HTTP seules : la règle vit dans {@see EditionDeLaPeriode}, et chaque refus porte
 * son motif (`reason`), que l'écran traduit.
 */
final class TrainingSessionEditionController extends AuthenticatedController
{
    public function __construct(private readonly EditionDeLaPeriode $edition) {}

    public function update(UpdateTrainingSessionRequest $request, int $trainingSession): JsonResponse
    {
        try {
            $periode = $this->edition->modifier($this->authenticatedUser($request), $trainingSession, $request->modifications());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse(PeriodeEnVue::de($periode), 'Période modifiée.');
    }

    public function destroy(Request $request, int $trainingSession): JsonResponse
    {
        try {
            $this->edition->supprimer($this->authenticatedUser($request), $trainingSession);
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse(null, 'Brouillon supprimé.');
    }

    public function enCours(Request $request, int $trainingSession): JsonResponse
    {
        try {
            $periode = $this->edition->designerEnCours($this->authenticatedUser($request), $trainingSession);
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse(PeriodeEnVue::de($periode), 'Période en cours désignée.');
    }
}
