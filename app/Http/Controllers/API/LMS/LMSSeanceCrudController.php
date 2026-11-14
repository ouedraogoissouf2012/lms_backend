<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\StoreLocalSeanceRequest;
use App\Services\Seances\LocalSeanceCreator;
use Illuminate\Http\JsonResponse;

/**
 * #710 — POST /api/lms/seances (création locale).
 *
 * Une classe qui n'est pas celle de l'établissement rend le refus du service
 * (404, « Classe introuvable dans cet établissement. »), et non un 500 : aucun
 * rendu global de BusinessException n'existe, le contrôleur l'attrape donc,
 * comme LMSClasseMatiereController.
 */
final class LMSSeanceCrudController extends AuthenticatedController
{
    public function __construct(
        private readonly LocalSeanceCreator $creator,
    ) {
    }

    public function store(StoreLocalSeanceRequest $request): JsonResponse
    {
        try {
            $seance = $this->creator->create(
                $this->authenticatedUser($request),
                $request->validated(),
            );
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse($seance, 'Séance créée', 201);
    }
}
