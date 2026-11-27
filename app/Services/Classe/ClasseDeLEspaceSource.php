<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\Classe;

/**
 * #1676 : la classe LOCALE désignée par l'identifiant de l'espace SOURCE de l'école, celui que
 * prennent `/lms/classes/{classeId}` et `/lms/classes/{classeId}/etudiants`, et que le front
 * présente (`idPourKlassci()`).
 *
 * - École créée depuis le LMS : un seul espace, `classes.id`.
 * - École KLASSCI : l'identité KLASSCI, lue sur le miroir local de l'école (`classes.klassci_id`).
 *   Tant que le miroir n'existe pas, aucune classe : l'appelant répond 404 (déclaré).
 *
 * Jamais « l'espace local d'abord, puis le miroir » : un même nombre désignerait deux classes.
 * Le volet est choisi au seul `RosterAuthorityFactory`.
 */
interface ClasseDeLEspaceSource
{
    public function trouver(int $identifiant, int $etablissement): ?Classe;
}
