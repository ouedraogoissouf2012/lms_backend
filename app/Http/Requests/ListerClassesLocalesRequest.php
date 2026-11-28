<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PlacesDeClasse;
use App\Enums\StatutDeClasse;
use Illuminate\Validation\Rule;

/**
 * Pagination de la liste des classes locales (#905) : les règles communes de
 * {@see ListePagineeRequest}, que suit aussi la liste des Périodes (#911).
 *
 * Ses filtres (#1652) : `statut` et `places`, facultatifs. Une valeur inconnue est
 * refusée (422), jamais ignorée : un filtre ignoré rendrait toute la liste en
 * laissant croire qu'elle est filtrée.
 */
final class ListerClassesLocalesRequest extends ListePagineeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'statut' => ['sometimes', 'string', Rule::enum(StatutDeClasse::class)],
            'places' => ['sometimes', 'string', Rule::enum(PlacesDeClasse::class)],
        ];
    }

    public function statut(): ?StatutDeClasse
    {
        return $this->enum('statut', StatutDeClasse::class);
    }

    public function places(): ?PlacesDeClasse
    {
        return $this->enum('places', PlacesDeClasse::class);
    }
}
