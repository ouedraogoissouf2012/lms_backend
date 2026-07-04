<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Dashboard;

use App\Http\Controllers\AuthenticatedController;
use App\Services\Dashboard\AdminDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DashboardAdminController — endpoint thin pour les stats globales.
 *
 * Issue #364 : la logique d'agrégation (users, cours, quiz, forum,
 * notifications, activité récente) est extraite verbatim dans
 * {@see AdminDashboardService}. Le controller se limite à :
 *
 *   1. Résoudre l'utilisateur authentifié de manière typée.
 *   2. Déléguer la construction du payload au service injecté.
 *   3. Sérialiser la réponse JSON dans le format `{success, data}`.
 *
 * ## DI strict (§1.6 D)
 *
 * `AdminDashboardService` est injecté par le constructeur ; le
 * controller n'exécute plus aucune requête Eloquent.
 *
 * @see app/Services/Dashboard/AdminDashboardService.php
 */
final class DashboardAdminController extends AuthenticatedController
{
    public function __construct(
        private readonly AdminDashboardService $service,
    ) {
    }

    /**
     * Statistiques globales (Admin/Coordinateur).
     *
     * GET /api/dashboard/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        return $this->successResponse($this->service->buildStats($user));
    }
}
