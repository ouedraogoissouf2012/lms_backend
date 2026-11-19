<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Pagination de la liste des classes locales (#905) : les règles communes de
 * {@see ListePagineeRequest}, que suit aussi la liste des Périodes (#911).
 */
final class ListerClassesLocalesRequest extends ListePagineeRequest {}
