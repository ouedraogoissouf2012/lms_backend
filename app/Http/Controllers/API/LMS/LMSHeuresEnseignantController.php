<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Presenters\HeuresEnseignantsCsv;
use App\Http\Requests\HeuresEnseignantsRequest;
use App\Http\Requests\MesHeuresRequest;
use App\Services\Attendances\Heures\HeuresDeLEnseignant;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * #1682 — les heures des enseignants, tirées de la visio : `GET /lms/mes-heures` (enseignant) et
 * `GET /lms/heures-enseignants` (gestion, JSON ou CSV). La source de l'école ({@see HeuresDeLEnseignant}) est reçue
 * par la MÉTHODE, jamais par le constructeur (ADR-EI-01).
 */
final class LMSHeuresEnseignantController extends AuthenticatedController
{
    public function mesHeures(MesHeuresRequest $request, HeuresDeLEnseignant $heures): JsonResponse
    {
        try {
            return $this->successResponse($heures->mesHeures($this->authenticatedUser($request), $request->mois()));
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }

    public function rapport(HeuresEnseignantsRequest $request, HeuresDeLEnseignant $heures): JsonResponse|StreamedResponse
    {
        try {
            $rapport = $heures->rapport($this->authenticatedUser($request), $request->filtres());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $request->enCsv() ? HeuresEnseignantsCsv::reponse($rapport) : $this->successResponse($rapport);
    }
}
