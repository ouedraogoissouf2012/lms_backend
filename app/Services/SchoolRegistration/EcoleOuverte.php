<?php

declare(strict_types=1);

namespace App\Services\SchoolRegistration;

use App\Models\Institution;
use App\Models\User;

/**
 * Ce qu'une validation produit (#803, ADR-803-02).
 *
 * Objet plutôt que tableau associatif, pour deux raisons :
 *
 *   - `ConnectionInterface::transaction()` rend `mixed`. Une closure ne peut
 *     pas déclarer une forme de tableau en type de retour, seulement `array` —
 *     le typage se perdait donc à la sortie de la transaction. Avec un type
 *     concret, la closure l'annonce et rien ne se perd. C'est le patron déjà
 *     employé par `ForumPostService` et `InstitutionCrudService`.
 *   - `activation` est le LIEN complet, jamais le jeton nu. Un nom de propriété
 *     typé le dit mieux qu'une clé de tableau qu'on peut lire de travers.
 *
 * Le lien n'existe qu'ici et dans la réponse HTTP : il n'est stocké nulle part
 * en clair.
 */
final class EcoleOuverte
{
    public function __construct(
        public readonly Institution $institution,
        public readonly User $proprietaire,
        public readonly string $lienActivation,
    ) {}
}
