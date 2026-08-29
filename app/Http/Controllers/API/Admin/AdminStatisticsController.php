<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\AuthenticatedController;
use App\Services\Dashboard\AdminStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #553 — GET /api/admin/statistics
 */
final class AdminStatisticsController extends AuthenticatedController
{
    public function __construct(
        private readonly AdminStatisticsService $service,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $this->authenticatedUser($request);

        return $this->successResponse($this->service->build());
    }
}
