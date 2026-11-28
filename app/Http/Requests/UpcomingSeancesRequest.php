<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TypeDeSeance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /lms/seances/upcoming` : le filtre `type` (#1668), dans la liste fermée.
 *
 * `days`, `teacher_id` et `classe_id` restent lus comme avant par le contrôleur : leur
 * donner une règle ici changerait une réponse existante (un `days` non entier rendrait
 * 422, là où il rend aujourd'hui une fenêtre). Hors de cette issue.
 *
 * Le rôle et l'établissement sont vérifiés par la route et par la source.
 */
final class UpcomingSeancesRequest extends FormRequest
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
            'type' => ['sometimes', 'nullable', Rule::enum(TypeDeSeance::class)],
        ];
    }

    public function typeDeSeance(): ?TypeDeSeance
    {
        $type = $this->validated('type');

        return is_string($type) ? TypeDeSeance::from($type) : null;
    }
}
