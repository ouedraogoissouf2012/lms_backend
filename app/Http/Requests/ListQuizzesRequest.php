<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates GET /api/quizzes query parameters — issue #548.
 *
 * QuizCrudController::index forwardait $request->all() brut à
 * QuizCrudService::list(). Toutes les clés lues par ce service (cf. son
 * docblock) sont listées ici pour que $request->validated() ne fasse
 * disparaître aucun filtre existant.
 */
final class ListQuizzesRequest extends FormRequest
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
            'lesson_id' => ['sometimes', 'integer', 'min:1'],
            'matiere_id' => ['sometimes', 'integer', 'min:1'],
            'classe_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string'],
            'type' => ['sometimes', 'string'],
            // Pas de contrainte `in:` sur `sort` : QuizCrudService::list() gère
            // déjà toute valeur inconnue via un `match(...) { default => ... }`
            // sûr (couvert par SqlInjectionTest::sort_param_on_quizzes_is_injection_safe).
            // Ajouter un enum ici durcirait un champ hors périmètre de #548 et
            // casserait ce test — laissé volontairement permissif.
            'sort' => ['sometimes', 'string'],
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
