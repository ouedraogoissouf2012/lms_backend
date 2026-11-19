<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Pagination de la liste des Périodes (#911) : les règles communes de
 * {@see ListePagineeRequest}. `?per_page=abc` rend 422, au lieu d'une Période par
 * page en silence.
 *
 * `?archivees=1` fait revenir les Périodes archivées, écartées par défaut (#1208).
 * Un booléen validé : `?archivees=abc` rend 422, comme `per_page`.
 */
final class ListerLesPeriodesRequest extends ListePagineeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + ['archivees' => ['sometimes']];
    }

    public function avecLesArchivees(): bool
    {
        return $this->boolean('archivees');
    }
}
