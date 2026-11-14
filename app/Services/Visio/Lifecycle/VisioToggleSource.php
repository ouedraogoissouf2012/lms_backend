<?php

declare(strict_types=1);

namespace App\Services\Visio\Lifecycle;

use App\Models\User;
use App\Services\Seances\Mutations\VisioToggleService;

/**
 * La bascule de la visio d'une séance (`toggle-visio`), par la source de
 * l'établissement (ADR-EI-01, « même route, autre source »).
 *
 * - École en mode KLASSCI : {@see VisioToggleService}.
 *   La séance est désignée par son identifiant KLASSCI, avec le jeton du compte.
 * - École en mode autonome : {@see LocalVisioToggle}. La séance est désignée par
 *   son id local, dans l'établissement, sans jeton ni appel KLASSCI.
 *
 * Liée par `VisioServiceProvider`, au seul `RosterAuthorityFactory::sourceForCurrentTenant`.
 * Le contrôleur la reçoit par sa MÉTHODE : Laravel garde le contrôleur sur l'objet
 * Route, et une source reçue à la construction resterait celle de la première école
 * servie.
 */
interface VisioToggleSource
{
    /** @return array{status:int, payload: array<string, mixed>} */
    public function toggle(int $seanceId, bool $enabled, ?string $visioType, User $user): array;
}
