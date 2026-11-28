<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksEvaluationOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * #1702 (D3) : `PUT /api/evaluations/{id}/tirages` (définir les règles de tirage : elles
 * remplacent les précédentes) et `GET` (les lire).
 *
 * Qui : la règle de MODIFICATION d'une évaluation pour écrire (l'auteur ou l'admin de l'école ;
 * jamais le coordinateur), la règle de LECTURE de ses notes pour lire
 * ({@see ChecksEvaluationOwnership}). Hors de l'école : 404 (#1782).
 */
final class DefinirLesTiragesRequest extends FormRequest
{
    use ChecksEvaluationOwnership;

    public function authorize(): bool
    {
        return $this->isMethod('get') ? $this->checkEvaluationReadAccess() : $this->checkEvaluationOwnership();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->isMethod('get') ? [] : [
            'tirages' => ['present', 'list', 'max:20'],
            'tirages.*.categorie_id' => ['required', 'integer', 'min:1', 'distinct'],
            'tirages.*.nombre' => ['required', 'integer', 'min:1', 'max:200'],
        ];
    }

    /** @return list<array{categorie_id: int, nombre: int}> */
    public function regles(): array
    {
        $regles = [];
        foreach ((array) $this->validated('tirages', []) as $regle) {
            if (is_array($regle) && is_numeric($regle['categorie_id'] ?? null) && is_numeric($regle['nombre'] ?? null)) {
                $regles[] = ['categorie_id' => (int) $regle['categorie_id'], 'nombre' => (int) $regle['nombre']];
            }
        }

        return $regles;
    }
}
