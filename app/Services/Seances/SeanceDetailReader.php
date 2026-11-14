<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\User;
use App\Services\SeanceDetailQueryService;

/**
 * Le détail d'une séance, `GET /lms/seances/{seanceId}/details` : même route,
 * autre source (ADR-EI-01, patron du lot 3d).
 *
 * - École en mode KLASSCI : {@see SeanceDetailQueryService}, la
 *   séance cherchée dans KLASSCI avec le jeton du compte.
 * - École en mode autonome : {@see LocalSeanceDetailReader}, la séance désignée
 *   par son id local, sous la règle de la liste.
 *
 * Liée par `CatalogueServiceProvider::SOURCES` ; le contrôleur la reçoit par sa
 * MÉTHODE (Laravel garde le contrôleur sur l'objet Route).
 */
interface SeanceDetailReader
{
    /**
     * La donnée du 200 et sa marque (`meta`), ou null : séance introuvable (404).
     *
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}|null
     */
    public function detailFor(int $seanceId, User $user): ?array;
}
