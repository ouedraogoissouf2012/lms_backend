<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Création d'un Programme (#827).
 *
 * `authorize()` rend `true` : l'accès est gardé par la route
 * (`role:coordinateur,admin,superAdmin`). Le dupliquer ici créerait deux
 * sources de vérité pour une même décision.
 *
 * `institution_id` n'a AUCUNE règle, et c'est le point : elle vient du tenant
 * résolu, jamais de la charge utile. L'accepter laisserait écrire chez le
 * voisin. `version` non plus — un Programme naît en version 1 ; le versionner
 * est une opération, pas un champ de formulaire.
 */
final class StoreProgramRequest extends FormRequest
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
            'titre' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Accesseurs TYPÉS. `validated()` rend `mixed` : convertir dans le
     * contrôleur masquerait un écart de contrat au lieu de le révéler. Le
     * FormRequest connaît ses propres règles — c'est lui qui sait que le titre
     * est une chaîne.
     */
    public function titre(): string
    {
        $titre = $this->validated('titre');

        return is_string($titre) ? $titre : '';
    }

    public function description(): ?string
    {
        $description = $this->validated('description');

        return is_string($description) ? $description : null;
    }
}
