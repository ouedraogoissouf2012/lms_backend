<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mode d'un établissement (#805) — une INTENTION déclarée, jamais déduite.
 *
 * `klassci` est le défaut et le restera : aucune ligne existante ne change de
 * comportement, aucune reprise de données n'est nécessaire.
 *
 * Ce mode ne se lit qu'au point de liaison déclaré (`RosterAuthorityFactory`).
 * Ailleurs, l'appelant demande une capacité, pas une nature — c'est l'article 1
 * de l'épique #697, et c'est ce que la garde `scripts/check-ocp.php` fait
 * respecter.
 */
enum InstitutionMode: string
{
    case Klassci = 'klassci';
    case Standalone = 'standalone';
}
