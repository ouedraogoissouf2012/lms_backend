<?php

declare(strict_types=1);

namespace App\Services\Visio\Lifecycle;

use App\Models\User;

/**
 * L'activation de la visio d'une séance, par la source de l'établissement
 * (ADR-EI-01, « même route, autre source »).
 *
 * - École en mode KLASSCI : {@see VisioActivationService}. La séance est cherchée
 *   dans l'emploi du temps KLASSCI, sous son identifiant KLASSCI, avec le jeton du
 *   compte.
 * - École en mode autonome : {@see LocalVisioActivation}. La séance est cherchée
 *   sous son id local, dans l'établissement, sans jeton ni appel KLASSCI.
 *
 * Liée par `VisioServiceProvider`, au seul `RosterAuthorityFactory::sourceForCurrentTenant`.
 * Le contrôleur la reçoit par sa MÉTHODE : Laravel garde le contrôleur sur l'objet
 * Route, et une source reçue à la construction resterait celle de la première école
 * servie.
 */
interface VisioActivationSource
{
    /**
     * @param  array<string, mixed>  $params  Payload validé (réservé futur)
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function activate(int $seanceId, User $user, array $params = []): array;
}
