<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\User;

/**
 * Le tableau de bord de l'enseignant, `GET /proxy/me/teacher-dashboard` (R4) :
 * même route, autre source (ADR-EI-01).
 *
 * - École en mode KLASSCI : {@see KlassciTeacherDashboardReader}, la charge de
 *   KLASSCI relayée telle quelle.
 * - École en mode autonome : {@see LocalTeacherDashboardReader}, la même forme,
 *   lue en local.
 *
 * Liée par `CatalogueServiceProvider::SOURCES` ; le contrôleur la reçoit par sa
 * MÉTHODE (Laravel garde le contrôleur sur l'objet Route).
 */
interface TeacherDashboardReader
{
    /**
     * Le corps de la réponse 200.
     *
     * @return array<string, mixed>
     */
    public function dashboardFor(User $teacher): array;
}
