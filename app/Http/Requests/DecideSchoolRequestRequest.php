<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation d'une demande d'ouverture d'école (#803, ADR-803-02).
 *
 * `authorize()` rend `true` : l'accès est gardé par la route elle-même —
 * `role:supradmin` + `platform.supradmin`, la double garde de #511 appliquée au
 * CRUD cross-tenant. Le dupliquer ici ferait deux sources de vérité pour une
 * même décision.
 *
 * Le `slug` est FACULTATIF et volontairement le seul champ acceptable : le
 * demandeur n'a exprimé qu'un souhait, jamais une réservation, et c'est ici que
 * le supradmin arbitre — collision avec un établissement existant comprise.
 * Son unicité n'est PAS vérifiée par cette règle mais par la contrainte de
 * table : la valider ici ouvrirait une fenêtre entre le contrôle et l'écriture.
 */
final class DecideSchoolRequestRequest extends FormRequest
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
            'slug' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[a-z0-9\-]+$/'],
        ];
    }

    public function slugImpose(): ?string
    {
        $slug = $this->input('slug');

        return is_string($slug) && $slug !== '' ? $slug : null;
    }
}
