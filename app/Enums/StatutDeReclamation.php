<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * #1678 : le circuit d'une réclamation de note. `Deposee` : l'élève a réclamé, l'enseignant doit
 * donner son avis. `AvisDonne` : la gestion doit trancher. `Tranchee` : la note est maintenue ou
 * corrigée, le circuit est clos.
 */
enum StatutDeReclamation: string
{
    case Deposee = 'deposee';
    case AvisDonne = 'avis_donne';
    case Tranchee = 'tranchee';
}
