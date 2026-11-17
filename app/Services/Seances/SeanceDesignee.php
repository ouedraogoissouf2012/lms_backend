<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\User;

/**
 * La séance que désigne une route de gestion (`DELETE /lms/seances/{id}`,
 * `POST /lms/seances/{id}/deactivate-visio`) : même route, autre source
 * (ADR-EI-01), l'espace d'identifiants suivant le mode déclaré de l'école
 * (ADR-760-01, amendement EI-02). #933.
 *
 * - École en mode KLASSCI : {@see KlassciSeanceDesignee}.
 * - École en mode autonome : {@see LocalSeanceDesignee}, l'id local seulement.
 *
 * Liée par `CatalogueServiceProvider::SOURCES` ; le contrôleur la reçoit par sa
 * MÉTHODE (Laravel garde le contrôleur sur l'objet Route).
 */
interface SeanceDesignee
{
    /** La séance de l'école du compte que désigne l'identifiant reçu, ou null. */
    public function pour(int $seanceId, User $user): ?Seance;
}
