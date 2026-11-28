<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Annonce\AnnonceAUneClasse;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `POST /api/annonces` (#1650) : une annonce à toute une classe.
 *
 * Le rôle est borné par la route (`role:enseignant,coordinateur,admin,superAdmin`). La classe — dans l'école de
 * l'acteur, et parmi les siennes pour un enseignant — est jugée APRÈS la validation, par
 * {@see AnnonceAUneClasse} : un `classe_id` mal formé est un 422, pas un 404.
 */
final class PublierUneAnnonceRequest extends FormRequest
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
            'classe_id' => ['required', 'integer', 'min:1'],
            'titre' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'classe_id.required' => 'Choisissez la classe à qui annoncer.',
            'titre.required' => 'Donnez un titre à l\'annonce.',
            'titre.max' => 'Le titre compte au plus 150 caractères.',
            'message.required' => 'Écrivez le message de l\'annonce.',
            'message.max' => 'Le message compte au plus 2 000 caractères.',
        ];
    }
}
