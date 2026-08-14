<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates GET /api/forum/topics query parameters — issue #548.
 *
 * Ne valide que `per_page` : {@see \App\Services\Forum\ForumTopicService::list()}
 * lit les autres filtres (lesson_id, matiere_id, classe_id, status, resolved,
 * sort) directement sur la requête, non concernés par cette issue.
 */
final class ListForumTopicsRequest extends FormRequest
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
            'per_page' => [
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
            'per_page.max' => 'per_page ne peut pas dépasser 100.',
        ];
    }
}
