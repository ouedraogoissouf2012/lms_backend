<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Programming;

use App\Models\User;

/**
 * La programmation des évaluations de l'appelant (`GET /api/proxy/evaluations`), par
 * type d'école (ADR-EI-01, « même route, autre source »).
 *
 * - École KLASSCI ({@see KlassciProgrammationDesEvaluations}) : relayée de KLASSCI,
 *   avec le jeton personnel (401 `klassci_token_missing` sans lui).
 * - École créée depuis le LMS ({@see LocalProgrammationDesEvaluations}) : il n'y a pas
 *   de programmation KLASSCI ; la liste est vide, sans 401 ni appel à KLASSCI.
 *
 * Liée par `CatalogueServiceProvider` (`bind`), reçue par la MÉTHODE du contrôleur.
 */
interface ProgrammationDesEvaluationsReader
{
    /**
     * @param  array<string, mixed>  $filtres  `matiere_id`, `classe_id`, `statut`
     * @return array<string, mixed>
     */
    public function list(User $user, array $filtres): array;
}
