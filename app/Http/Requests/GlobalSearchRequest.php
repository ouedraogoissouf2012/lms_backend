<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates GET /api/search query parameters — issue #548.
 *
 * Fusionne la règle `query` précédemment validée inline dans
 * `SearchController::globalSearch` (déplacée ici plutôt que dupliquée à
 * 2 endroits). `limit` fan-out sur 5 sous-requêtes indépendantes
 * ({@see \App\Services\Search\GlobalSearchService}) — plafond volontairement
 * plus bas (20) que les listings classiques (100).
 */
final class GlobalSearchRequest extends FormRequest
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
            'query' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => [
                'sometimes',
                'integer',
                'min:1',
                'max:20', // anti-DOS (#548) — fan-out ×5 sous-requêtes
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'limit.max' => 'limit ne peut pas dépasser 20.',
        ];
    }
}
