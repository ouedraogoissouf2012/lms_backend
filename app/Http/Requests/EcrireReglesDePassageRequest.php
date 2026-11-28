<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `PUT /api/regles-de-passage` (école) et `PUT /api/classes/{classe}/regles-de-passage` (surcharge), #1663.
 * Tous les champs sont facultatifs. Pour une classe, `null` remet un champ à l'héritage de l'école ; pour l'école, une
 * valeur est attendue. La comparaison rattrapage / passage se fait au service, sur les valeurs en vigueur.
 */
final class EcrireReglesDePassageRequest extends FormRequest
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
        $vide = $this->route('classe') !== null ? 'nullable' : 'filled';

        return [
            'moyenne_passage' => ['sometimes', $vide, 'numeric', 'min:0', 'max:20'],
            'moyenne_rattrapage' => ['sometimes', $vide, 'numeric', 'min:0', 'max:20'],
            'max_matieres_rattrapage' => ['sometimes', $vide, 'integer', 'min:0', 'max:20'],
            'redoublement_autorise' => ['sometimes', $vide, 'boolean'],
            'max_redoublements' => ['sometimes', $vide, 'integer', 'min:0', 'max:10'],
        ];
    }
}
