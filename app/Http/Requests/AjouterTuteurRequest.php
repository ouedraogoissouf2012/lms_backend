<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Tuteur;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `POST /api/eleves/{eleve}/tuteurs` (#1657) : la relation, et soit un tuteur EXISTANT de l'école
 * (`tuteur_id`, jugé par le service), soit les coordonnées d'un tuteur neuf. Aucun champ libre (loi 2013-450).
 */
final class AjouterTuteurRequest extends FormRequest
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
            'relation' => ['required', Rule::in(Tuteur::RELATIONS)],
            'tuteur_id' => ['sometimes', 'integer', 'min:1'],
            'nom' => ['required_without:tuteur_id', 'prohibits:tuteur_id', 'string', 'max:150'],
            ...ModifierTuteurRequest::coordonnees(),
        ];
    }
}
