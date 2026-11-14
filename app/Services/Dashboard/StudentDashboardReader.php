<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\User;

/**
 * Le tableau de bord de l'élève, `GET /proxy/me/dashboard` : même route, autre
 * source (ADR-EI-01), comme celui de l'enseignant ({@see TeacherDashboardReader}).
 *
 * - École en mode KLASSCI : {@see KlassciStudentDashboardReader}, la charge de
 *   KLASSCI relayée telle quelle.
 * - École en mode autonome : {@see LocalStudentDashboardReader}, la même forme,
 *   lue en local.
 *
 * Liée par `CatalogueServiceProvider::SOURCES` ; le contrôleur la reçoit par sa
 * MÉTHODE (Laravel garde le contrôleur sur l'objet Route). Un refus est levé :
 * `MissingKlassciTokenException` (KLASSCI), `BusinessException` motivée (local).
 */
interface StudentDashboardReader
{
    /**
     * Le corps de la réponse 200.
     *
     * @return array<string, mixed>
     */
    public function dashboardFor(User $student): array;
}
