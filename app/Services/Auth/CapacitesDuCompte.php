<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * Ce que la connexion dit au front du compte connecté : des CAPACITÉS, jamais le mode de l'école (#805).
 *
 * Un objet, et non des booléens positionnels : trois drapeaux voisins de même type s'inversent sans
 * qu'aucun type ne l'empêche. Calculé par {@see LesCapacitesDuCompte}, transporté tel quel par le
 * présentateur, qui ne décide rien.
 *
 * - `peut_inscrire_localement` : l'école inscrit ses apprenants dans le LMS (#805) ;
 * - `peut_gerer_catalogue_local` : elle tient son catalogue (classes, matières, sessions) dans le LMS (#1184) ;
 * - `peut_suivre_les_presences` : elle tient ses présences dans le LMS (#1683, front#689).
 *
 * Toutes trois portent sur l'ÉCOLE ; le rôle reste jugé par chaque route.
 */
final readonly class CapacitesDuCompte
{
    public function __construct(
        public bool $peutInscrireLocalement,
        public bool $peutGererCatalogueLocal,
        public bool $peutSuivreLesPresences,
    ) {}

    /** Aucune capacité : l'autorité la plus restrictive, celle d'un compte sans établissement. */
    public static function aucune(): self
    {
        return new self(peutInscrireLocalement: false, peutGererCatalogueLocal: false, peutSuivreLesPresences: false);
    }

    /** @return array{peut_inscrire_localement: bool, peut_gerer_catalogue_local: bool, peut_suivre_les_presences: bool} */
    public function versMeta(): array
    {
        return [
            'peut_inscrire_localement' => $this->peutInscrireLocalement,
            'peut_gerer_catalogue_local' => $this->peutGererCatalogueLocal,
            'peut_suivre_les_presences' => $this->peutSuivreLesPresences,
        ];
    }
}
