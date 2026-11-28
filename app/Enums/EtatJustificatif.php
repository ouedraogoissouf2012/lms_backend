<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * L'état d'un justificatif d'absence (#1681) : déposé, puis tranché UNE fois par la gestion.
 */
enum EtatJustificatif: string
{
    case EnAttente = 'en_attente';
    case Validee = 'validee';
    case Rejetee = 'rejetee';
}
