<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * #1678 : les types de notification d'une réclamation de note, sortis de `Notification` pour la garde de taille
 * des modèles (§5, ≤150 lignes). `Notification` les porte par ce trait : les appelants écrivent toujours
 * `Notification::TYPE_RECLAMATION_*`, et la liste blanche `Notification::TYPES` les compte.
 */
trait TypesDeReclamation
{
    /** Une réclamation déposée : l'enseignant de l'évaluation est averti. */
    public const TYPE_RECLAMATION_DEPOSEE = 'reclamation_deposee';

    /** L'avis de l'enseignant est donné : la gestion a la réclamation à trancher. */
    public const TYPE_RECLAMATION_A_TRANCHER = 'reclamation_a_trancher';

    /** La réclamation est tranchée : l'élève est averti. */
    public const TYPE_RECLAMATION_TRANCHEE = 'reclamation_tranchee';
}
