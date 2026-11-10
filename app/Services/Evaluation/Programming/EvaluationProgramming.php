<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Programming;

use App\Models\User;

/**
 * La programmation d'une évaluation qu'on crée : à quelle classe, à quelle
 * matière, et sous quels libellés elle se rattache (D72).
 *
 * Deux sources, choisies au seul point de liaison
 * (`RosterAuthorityFactory::sourceForCurrentTenant`, ADR-EI-01) :
 * - école créée depuis KLASSCI : l'évaluation met en ligne une évaluation
 *   PROGRAMMÉE dans KLASSCI, désignée par ses identifiants `klassci_*` ;
 * - école créée depuis le LMS : l'évaluation se programme dans le LMS, sur une
 *   classe et une matière LOCALES (`classe_id`, `matiere_id`).
 *
 * Chaque source refuse les identifiants de l'autre : un id local ne vit
 * jamais sous un nom `klassci_*`, même dans une requête (D132).
 *
 * Le contrôleur reçoit ce port par sa MÉTHODE, jamais par son constructeur :
 * Laravel garde le contrôleur sur l'objet `Route`, et une source reçue à la
 * construction resterait celle de la première école servie.
 */
interface EvaluationProgramming
{
    /**
     * @param  array<string, mixed>  $donnees  Corps validé par `StoreEvaluationRequest`.
     */
    public function programmer(array $donnees, User $auteur): ProgrammedEvaluation|EvaluationProgrammingRefusal;
}
