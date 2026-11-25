<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\User;

/**
 * Les évaluations d'un enseignant, et les classes et matières qu'elles touchent (#1624).
 *
 * Le volet de l'école décide de la clé : une école autonome programme ses évaluations sur ses
 * classes et matières LOCALES, une école KLASSCI sur les identifiants KLASSCI. Le choix se fait au
 * seul `RosterAuthorityFactory`. Quel que soit le volet, les classes et matières rendues sont des
 * ids LOCAUX : les leçons parlent cet espace-là, et l'union ne doit jamais confondre deux espaces.
 */
interface ContenusEvaluesDeLEnseignant
{
    /**
     * @return array{evaluations: int, classes: list<int>, matieres: list<int>}
     */
    public function de(User $enseignant, int $ecole): array;
}
