<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TypeDeSeance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * La forme d'une modification de séance (`PUT|PATCH /lms/seances/{seanceId}`,
 * #1121) : les champs de la création que le user a déclarés modifiables, chacun
 * facultatif. La matière ne se change pas.
 *
 * Qui modifie, et sur quelle séance, est décidé par la source de l'école
 * (`ModificationDeSeance`) ; le rôle est gardé par la route (`personnel`).
 */
final class UpdateLocalSeanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titre' => 'sometimes|required|string|min:3|max:255',
            'date_seance' => ['sometimes', 'required', 'date', 'regex:'.StoreLocalSeanceRequest::DATE_ISO_AVEC_DECALAGE],
            // Aucune règle `exists` : l'appartenance à l'établissement et le programme
            // sont vérifiés par la source, comme à la création.
            'classe_id' => 'sometimes|nullable|integer|min:1',
            'classe_nom' => 'sometimes|nullable|string|max:191',
            'type_seance' => ['sometimes', 'nullable', Rule::enum(TypeDeSeance::class)],
            // Pas de `after:date_seance` : le début peut ne pas être envoyé. L'ordre se
            // vérifie sur les valeurs finales, dans la source (LocalModificationDeSeance).
            'fin_seance' => ['sometimes', 'nullable', 'date', 'regex:'.StoreLocalSeanceRequest::DATE_ISO_AVEC_DECALAGE],
            'forcer_conflit' => 'sometimes|boolean',
        ];
    }
}
