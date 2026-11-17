<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\StoreLocalSeanceRequest;
use App\Http\Requests\UpdateLocalSeanceRequest;
use App\Services\Seances\LocalSeanceCreator;
use App\Services\Seances\ModificationDeSeance;
use Illuminate\Http\JsonResponse;

/**
 * #710 — POST /api/lms/seances (création locale).
 *
 * #1121 — PUT|PATCH /api/lms/seances/{seanceId} (modification) : la source de
 * l'école ({@see ModificationDeSeance}) est reçue par la MÉTHODE, jamais par le
 * constructeur (Laravel garde le contrôleur sur l'objet Route, ADR-EI-01).
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

    public function update(UpdateLocalSeanceRequest $request, int $seanceId, ModificationDeSeance $modification): JsonResponse
    {
        try {
            $seance = $modification->modifier($seanceId, $this->authenticatedUser($request), $request->validated());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse($seance, 'Séance modifiée');
    }
}
