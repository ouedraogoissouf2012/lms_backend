<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Le filtre `statut` de la collection des classes locales (#1652) : une classe est active tant
 * qu'elle n'a pas de date d'archive (`classes.archivee_le`).
 */
enum StatutDeClasse: string
{
    case Active = 'active';
    case Archivee = 'archivee';
}
