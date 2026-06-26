<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithJson;

abstract class Controller
{
    use RespondsWithJson;
}
