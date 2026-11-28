<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * #1677 (B1) : `PUT /api/lms/bulletins/modele` — le modèle de bulletin de l'école, par la gestion
 * (admin, coordinateur). Tout est borné : six lignes d'en-tête, six signataires, douze tranches de
 * barème sur 20, vingt blocs de matières.
 */
final class EcrireLeModeleDeBulletinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isManager() === true;
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'entete' => 'present|list|max:6',
            'entete.*' => 'string|max:200',
            'signataires' => 'present|list|max:6',
            'signataires.*.fonction' => 'required|string|max:100',
            'signataires.*.nom' => 'required|string|max:100',
            'bareme' => 'present|list|max:12',
            'bareme.*.min' => 'required|numeric|min:0|max:20',
            'bareme.*.max' => 'required|numeric|min:0|max:20|gte:bareme.*.min',
            'bareme.*.libelle' => 'required|string|max:100',
            'blocs' => 'present|list|max:20',
            'blocs.*.libelle' => 'required|string|max:100',
            'blocs.*.matiere_ids' => 'present|list',
            'blocs.*.matiere_ids.*' => 'integer|min:1',
            'mentions' => 'sometimes|array',
            'mentions.rang' => 'sometimes|boolean',
            'mentions.moyenne_classe' => 'sometimes|boolean',
            'mentions.assiduite' => 'sometimes|boolean',
        ];
    }
}
