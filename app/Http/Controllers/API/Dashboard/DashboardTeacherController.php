<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Dashboard;

use App\Http\Controllers\AuthenticatedController;
use App\Services\Dashboard\TeacherDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DashboardTeacherController — endpoint thin pour le dashboard enseignant.
 *
 * Issue #364 : la logique d'agrégation (cours, étudiants actifs, quiz à
 * corriger, topics forum) est extraite verbatim dans
 * {@see TeacherDashboardService}. Le controller se limite à :
 *
 *   1. Résoudre l'utilisateur authentifié de manière typée.
 *   2. Déléguer la construction du payload au service injecté.
 *   3. Sérialiser la réponse JSON dans le format `{success, data}`.
 *
 * ## DI strict (§1.6 D)
 *
 * `TeacherDashboardService` est injecté par le constructeur ; le
 * controller n'exécute plus aucune requête Eloquent.
 *
 * @see app/Services/Dashboard/TeacherDashboardService.php
 */
final class DashboardTeacherController extends AuthenticatedController
{
    public function __construct(
        private readonly TeacherDashboardService $service,
    ) {
    }

    /**
     * Dashboard Enseignant.
     *
     * GET /api/dashboard/teacher
     *
     * Retourne :
     *   - Statistiques cours créés + top engagement
     *   - Étudiants actifs (7 derniers jours)
     *   - Quiz à corriger
     *   - Topics forum non résolus
     */
    public function teacher(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        return $this->successResponse($this->service->buildDashboard($user));
    }
}
