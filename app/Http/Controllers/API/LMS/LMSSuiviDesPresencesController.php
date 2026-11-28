<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\SuiviDesPresencesRequest;
use App\Services\Attendances\Suivi\SuiviDesPresences;
use Illuminate\Http\JsonResponse;

/**
 * #1683 — le suivi des présences de l'école, pour la gestion : `GET /lms/presences` (la liste filtrée) et
 * `GET /lms/presences/synthese` (par élève, par classe, sous le seuil). La source de l'école
 * ({@see SuiviDesPresences}) est reçue par la MÉTHODE, jamais par le constructeur (ADR-EI-01).
 */
final class LMSSuiviDesPresencesController extends AuthenticatedController
{
    public function index(SuiviDesPresencesRequest $request, SuiviDesPresences $suivi): JsonResponse
    {
        try {
            return $this->successResponse($suivi->lister($this->authenticatedUser($request), $request->filtres()));
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }

    public function synthese(SuiviDesPresencesRequest $request, SuiviDesPresences $suivi): JsonResponse
    {
        try {
            return $this->successResponse($suivi->synthese($this->authenticatedUser($request), $request->filtres(), $request->seuil()));
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }
}
