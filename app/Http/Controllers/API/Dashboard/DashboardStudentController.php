<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Dashboard;

use App\Http\Controllers\AuthenticatedController;
use App\Services\Dashboard\StudentDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DashboardStudentController — endpoint thin pour le dashboard étudiant.
 *
 * Split-26/dashboard-student : la logique d'agrégation (cours, quiz,
 * progression, notifications, forum) est extraite verbatim dans
 * {@see StudentDashboardService}. Le controller se limite à :
 *
 *   1. Résoudre l'utilisateur authentifié de manière typée.
 *   2. Déléguer la construction du payload au service injecté.
 *   3. Sérialiser la réponse JSON dans le format `{success, data}`.
 *
 * ## DI strict (§1.6 D)
 *
 * `StudentDashboardService` est injecté par le constructeur ; le
 * controller n'instancie plus directement de presenter ni de Facade.
 *
 * @see app/Services/Dashboard/StudentDashboardService.php
 */
final class DashboardStudentController extends AuthenticatedController
{
    public function __construct(
        private readonly StudentDashboardService $service,
    ) {
    }

    /**
     * Dashboard Étudiant.
     *
     * GET /api/dashboard/student
     *
     * Retourne :
     *   - Cours en cours (in_progress)
     *   - Cours complétés (completed)
     *   - Prochains quiz
     *   - Progression globale (leçons + quiz)
     *   - Notifications récentes + compteur non-lu
     *   - Activité forum récente
     */
    public function student(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        return $this->successResponse($this->service->buildDashboard($user));
    }
}
