<?php

declare(strict_types=1);

namespace App\Enums;

enum ConsentPurpose: string
{
    case Capture = 'capture';
    case Diffusion = 'diffusion';
    case Reutilisation = 'reutilisation';
}
