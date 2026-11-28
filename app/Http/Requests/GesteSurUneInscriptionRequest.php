<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates les gestes de l'école sur une inscription (#1658), selon la route :
 * - `annuler` : `motif` requis ;
 * - `reserve` : `condition` requise, `piece` facultative ;
 * - `PUT /inscriptions/{inscription}` : la fiche (redoublant, établissement d'origine, affectation) ;
 * - `PUT /inscriptions/reglage` : `a_valider`.
 */
final class GesteSurUneInscriptionRequest extends FormRequest
{
    public const AFFECTATIONS = ['affecte', 'reaffecte', 'non_affecte'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $chemin = $this->path();

        return match (true) {
            str_ends_with($chemin, '/annuler') => ['motif' => ['required', 'string', 'max:500']],
            str_ends_with($chemin, '/reserve') => ['condition' => ['required', 'string', 'max:255'], 'piece' => ['sometimes', 'nullable', 'string', 'max:100']],
            str_ends_with($chemin, '/reglage') => ['a_valider' => ['required', 'boolean']],
            default => [
                'redoublant' => ['sometimes', 'nullable', 'boolean'],
                'etablissement_origine' => ['sometimes', 'nullable', 'string', 'max:150'],
                'affectation' => ['sometimes', 'nullable', Rule::in(self::AFFECTATIONS)],
            ],
        };
    }

    public function texte(string $cle): ?string
    {
        $valeur = $this->validated($cle);

        return is_string($valeur) && trim($valeur) !== '' ? trim($valeur) : null;
    }
}
