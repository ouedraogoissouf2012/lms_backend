<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Pagination de la liste des Périodes (#911) : les règles communes de
 * {@see ListePagineeRequest}. `?per_page=abc` rend 422, au lieu d'une Période par
 * page en silence.
 */
final class ListerLesPeriodesRequest extends ListePagineeRequest {}
