<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rattacher une classe à une Période, ou l'en détacher (#1208, point 1).
 *
 * `training_session_id` est EXIGÉ, nul compris : un corps vide ne dit pas si l'on veut
 * détacher, et deviner serait effacer un rattachement par erreur. L'existence et l'état
 * de la Période sont vérifiés au point d'écriture, dans l'établissement.
 *
 * `authorize()` rend `true` : le rôle est vérifié par le groupe de la route, le droit
 * d'écrire par la porte du catalogue.
 */
final class RattacherClasseRequest extends FormRequest
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
            'training_session_id' => ['present', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function periode(): ?int
    {
        return $this->filled('training_session_id') ? $this->integer('training_session_id') : null;
    }
}
