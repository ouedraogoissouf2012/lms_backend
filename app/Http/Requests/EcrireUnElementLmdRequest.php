<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\NiveauLmd;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Créer (POST) ou modifier (PATCH) un élément de l'arbre LMD (#1654). Le niveau est fixé par la route
 * (`defaults('niveau', …)`), jamais par le client.
 *
 * À la création : `libelle` exigé, et le parent (`domaine_id` pour une mention, `mention_id` pour un parcours). À la
 * modification, seuls les champs envoyés changent ; `actif: false` désactive. Que le parent soit de l'école, c'est
 * le service qui le dit (422 sous son champ).
 *
 * `authorize()` rend `true` : le rôle est vérifié par le groupe de la route, le droit par la porte du catalogue.
 */
final class EcrireUnElementLmdRequest extends FormRequest
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
        $exige = $this->isMethod('POST') ? 'required' : 'sometimes';
        $regles = [
            'libelle' => [$exige, 'string', 'max:191', 'not_regex:/^\s*$/'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'actif' => ['sometimes', 'boolean'],
        ];

        $parent = $this->niveau()->colonneDuParent();
        if ($parent !== null) {
            $regles[$parent] = [$exige, 'integer', 'min:1'];
        }

        return $regles;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['libelle.not_regex' => 'Le libellé est obligatoire.'];
    }

    public function niveau(): NiveauLmd
    {
        return NiveauLmd::from((string) $this->route('niveau'));
    }

    /** @return array<string, mixed> */
    public function donnees(): array
    {
        return $this->validated();
    }
}
