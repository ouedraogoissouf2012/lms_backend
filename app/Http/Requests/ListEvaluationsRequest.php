<?php

declare(strict_types=1);

namespace App\Http\Requests;

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
}
