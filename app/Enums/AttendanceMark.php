<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Présence pédagogique (#717). Distinct du `status` visio
 * (`connected`/`disconnected`/`kicked`) sur la même ligne.
 */
enum AttendanceMark: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Late = 'late';
    case Excused = 'excused';
}
