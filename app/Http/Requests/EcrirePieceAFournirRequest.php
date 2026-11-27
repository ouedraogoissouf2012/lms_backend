<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PieceAFournir;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `POST /api/pieces-a-fournir` (création) et `PUT /api/pieces-a-fournir/{piece}` (modification, champs
 * facultatifs), #1661. Le `code` (minuscules, chiffres, `_`) est unique dans l'école de l'acteur, hors la pièce
 * modifiée ; il ne change pas une fois posé, car une réserve d'inscription l'attend.
 */
final class EcrirePieceAFournirRequest extends FormRequest
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
        $creation = $this->isMethod('post');
        $requis = $creation ? 'required' : 'sometimes';
        $ecole = $this->user()?->institution_id;

        return [
            'code' => $creation
                ? ['required', 'string', 'regex:/^[a-z0-9_]{2,40}$/', Rule::unique('pieces_a_fournir', 'code')->where('institution_id', is_int($ecole) ? $ecole : 0)]
                : ['prohibited'],
            'libelle' => [$requis, 'string', 'max:150'],
            'consigne' => ['sometimes', 'nullable', 'string', 'max:500'],
            'forme' => [$requis, Rule::in(PieceAFournir::FORMES)],
            'quantite' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'portee' => [$requis, Rule::in(PieceAFournir::PORTEES)],
            'validite_jours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'obligatoire' => ['sometimes', 'boolean'],
            'en_service' => [$creation ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Le code s\'écrit en minuscules, chiffres et « _ » (2 à 40 caractères).',
            'code.unique' => 'Ce code est déjà celui d\'une autre pièce de l\'établissement.',
            'code.prohibited' => 'Le code d\'une pièce ne change pas : une réserve d\'inscription peut l\'attendre.',
        ];
    }
}
