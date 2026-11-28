<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changer un élève de classe (#1652, PR C) : la classe cible, `classe_id`, EXIGÉE et
 * différente de la classe source (celle du chemin). Qu'elle soit de l'école, qu'elle
 * reçoive encore, c'est le service qui le dit.
 *
 * `authorize()` rend `true` : le rôle est vérifié par le groupe de la route, le droit
 * d'écrire par la porte du catalogue.
 */
final class MuterEleveRequest extends FormRequest
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
            'classe_id' => ['required', 'integer', 'min:1', Rule::notIn([(int) $this->route('classe')])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'classe_id.not_in' => 'L\'élève est déjà dans cette classe : choisissez-en une autre.',
        ];
    }

    public function cible(): int
    {
        return $this->integer('classe_id');
    }
}
