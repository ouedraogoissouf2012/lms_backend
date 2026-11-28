<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\SaisirLAppelRequest;
use App\Services\Attendances\Appel\SaisieDeLAppel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #1649 — l'appel d'une séance : `GET` et `PUT /lms/seances/{seanceId}/appel`.
 *
 * La source de l'école ({@see SaisieDeLAppel}) est reçue par la MÉTHODE, jamais par le constructeur : Laravel garde
 * le contrôleur sur l'objet Route (ADR-EI-01). Le `PUT` rend l'appel relu.
 */
final class LMSAppelController extends AuthenticatedController
{
    public function show(Request $request, int $seanceId, SaisieDeLAppel $appel): JsonResponse
    {
        try {
            return $this->successResponse($appel->lire($seanceId, $this->authenticatedUser($request)));
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }

    public function update(SaisirLAppelRequest $request, int $seanceId, SaisieDeLAppel $appel): JsonResponse
    {
        $acteur = $this->authenticatedUser($request);
        try {
            $appel->saisir($seanceId, $acteur, $request->marques());

            return $this->successResponse($appel->lire($seanceId, $acteur), 'Appel enregistré');
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }
}
