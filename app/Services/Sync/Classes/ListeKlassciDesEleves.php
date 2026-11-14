<?php

declare(strict_types=1);

namespace App\Services\Sync\Classes;

use App\Services\Seances\KlassciPayload;

/**
 * La liste des élèves d'une classe, telle que `classes/{id}` la rend, avec
 * l'effectif que la même réponse annonce (`places_occupees`).
 *
 * Elle ne fait FOI que si elle est prouvée complète :
 * - l'effectif annoncé est un entier ;
 * - il est égal au nombre d'élèves reçus ;
 * - chaque élève porte un identifiant KLASSCI entier, sans doublon.
 *
 * Mesure de production (#669) : la liste de `classes/{id}` est le roster ACTIF, et
 * sa taille égale `places_occupees`. Un bloc ABSENT n'est jamais une liste : il ne
 * se construit pas ici.
 */
final class ListeKlassciDesEleves
{
    /**
     * @param  array<int, mixed>  $etudiants
     */
    public function __construct(
        private readonly array $etudiants,
        private readonly mixed $effectifAnnonce,
    ) {}

    public function estComplete(): bool
    {
        $ids = $this->identifiants();

        return $ids !== null && KlassciPayload::toInt($this->effectifAnnonce) === count($this->etudiants);
    }

    /**
     * Les identifiants KLASSCI des élèves reçus ; `null` si l'un manque, n'est pas
     * entier, ou revient deux fois.
     *
     * @return list<int>|null
     */
    public function identifiants(): ?array
    {
        $ids = [];
        foreach ($this->etudiants as $etudiant) {
            $id = is_array($etudiant) ? KlassciPayload::toInt($etudiant['id'] ?? null) : null;
            if ($id === null || in_array($id, $ids, true)) {
                return null;
            }
            $ids[] = $id;
        }

        return $ids;
    }

    public function nombreRecu(): int
    {
        return count($this->etudiants);
    }

    public function effectifAnnonce(): mixed
    {
        return $this->effectifAnnonce;
    }
}
