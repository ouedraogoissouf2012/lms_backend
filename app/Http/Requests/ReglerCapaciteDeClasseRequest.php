<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Catalogue\CapaciteDeClasse;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Régler la capacité d'une classe (#1358).
 *
 * `effectif` est EXIGÉ, nul compris : un corps vide ne dit pas si l'on veut retirer la
 * limite, et deviner serait l'effacer par erreur (même règle que la Période, #1208).
 * `null` ou 0 : sans limite.
 *
 * `authorize()` rend `true` : le rôle est vérifié par le groupe de la route, le droit
 * d'écrire par la porte du catalogue.
 */
final class ReglerCapaciteDeClasseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'effectif' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.CapaciteDeClasse::MAXIMUM],
        ];
    }

    public function capacite(): ?int
    {
        return $this->filled('effectif') ? $this->integer('effectif') : null;
    }
}
