<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesQueryBooleans;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates GET /api/evaluations query parameters — issue #548.
 *
 * `limit` (pas `per_page` : cet endpoint n'a pas de pagination réelle,
 * {@see \App\Services\Evaluation\EvaluationListService::listForTeacher()}
 * retourne un tableau plat, jamais une enveloppe paginée).
 */
final class ListEvaluationsRequest extends FormRequest
{
    use NormalizesQueryBooleans;

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
            'classe_id' => ['sometimes', 'integer', 'min:1'],
            'matiere_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string'],
            'is_published' => ['sometimes', 'boolean'],
            'limit' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100', // anti-DOS (#548)
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'limit.max' => 'limit ne peut pas dépasser 100.',
        ];
    }

    /**
     * `is_published` arrive par la chaîne de requête, donc en TEXTE. Le défaut
     * était ici LATENT — aucun écran ne pose encore ce filtre — mais il est
     * strictement le même que celui mesuré sur `unread_only`, et le premier
     * client qui l'utiliserait (SDK généré, mobile) recevrait un 422.
     * Voir {@see NormalizesQueryBooleans}.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeQueryBooleans(['is_published']);
    }
}
