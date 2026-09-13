<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Comment la marque a été établie (#717). Sans ça le relevé n'est pas défendable.
 */
enum AttendanceOrigin: string
{
    case Visio = 'visio';
    case Code = 'code';
    case Delegated = 'delegated';
    case Paper = 'paper';
}
