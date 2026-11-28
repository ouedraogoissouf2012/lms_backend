<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Créer (POST) ou modifier (PATCH) une filière ou un niveau d'études (#1648).
 *
 * `libelle` est exigé à la création, facultatif à la modification (seuls les champs envoyés changent).
 * `code` nul ou vide : sans code. `ordre` n'est lu que pour un niveau (le service l'ignore pour une
 * filière).
 *
 * `authorize()` rend `true` : le rôle est vérifié par le groupe de la route, le droit d'écrire par la porte
 * du catalogue.
 */
final class EcrireUnElementDeStructureRequest extends FormRequest
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
        $libelle = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'libelle' => [$libelle, 'string', 'max:191', 'not_regex:/^\s*$/'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'ordre' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['libelle.not_regex' => 'Le libellé est obligatoire.'];
    }

    /** @return array{libelle?: string, code?: ?string, ordre?: ?int} */
    public function donnees(): array
    {
        /** @var array{libelle?: string, code?: ?string, ordre?: ?int} $valide */
        $valide = $this->validated();

        return $valide;
    }
}
