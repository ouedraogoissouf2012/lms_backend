<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Pagination de la liste des Programmes (#1206) : les règles communes de
 * {@see ListePagineeRequest}. `?per_page=101` rend 422, comme pour les Périodes.
 */
final class ListerLesProgrammesRequest extends ListePagineeRequest {}
