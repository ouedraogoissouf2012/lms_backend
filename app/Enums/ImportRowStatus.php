<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportRowStatus: string
{
    case Ok = 'ok';
    case Error = 'error';
}
