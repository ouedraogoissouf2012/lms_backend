<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `PUT /api/tuteurs/{tuteur}` (#1657) : les coordonnées d'un tuteur, seules (aucun champ libre). Elles sont
 * partagées avec l'ajout ({@see AjouterTuteurRequest}).
 */
final class ModifierTuteurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Les coordonnées d'un tuteur, et leurs bornes : une source pour l'ajout et la modification.
     *
     * @return array<string, list<string>>
     */
    public static function coordonnees(): array
    {
        return [
            'telephone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'courriel' => ['sometimes', 'nullable', 'email', 'max:255'],
            'profession' => ['sometimes', 'nullable', 'string', 'max:100'],
            'adresse' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['nom' => ['sometimes', 'string', 'max:150'], ...self::coordonnees()];
    }
}
