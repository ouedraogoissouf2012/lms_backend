<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\StoreLocalSeanceRequest;
use App\Services\Seances\LocalSeanceCreator;
use Illuminate\Http\JsonResponse;

/**
 * #710 — POST /api/lms/seances (création locale).
 */
final class LMSSeanceCrudController extends AuthenticatedController
{
    public function __construct(
        private readonly LocalSeanceCreator $creator,
    ) {
    }

    public function store(StoreLocalSeanceRequest $request): JsonResponse
    {
        $seance = $this->creator->create(
            $this->authenticatedUser($request),
            $request->validated(),
        );

        return $this->successResponse($seance, 'Séance créée', 201);
    }
}
