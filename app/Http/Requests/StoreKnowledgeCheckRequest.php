<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valide la création d'un knowledge check (POST /api/knowledge-checks).
 * Autorisation propriétaire chapitre vérifiée dans KnowledgeCheckCrudService.
 */
final class StoreKnowledgeCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'chapter_id' => 'required|exists:chapters,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'questions' => 'required|array|min:1',
            'questions.*.question' => 'required|string',
            'questions.*.type' => 'required|in:single,multiple,true_false',
            'questions.*.options' => 'required|array|min:2',
            'questions.*.correct_answer' => 'required',
            'questions.*.explanation' => 'nullable|string',
            'questions.*.points' => 'nullable|integer|min:1',
            'passing_score' => 'nullable|integer|min:0|max:100',
            'max_attempts' => 'nullable|integer|min:1',
            'shuffle_questions' => 'nullable|boolean',
            'shuffle_options' => 'nullable|boolean',
            'show_correct_answers' => 'nullable|boolean',
            'show_explanation' => 'nullable|boolean',
            'time_limit_minutes' => 'nullable|integer|min:1',
            'position' => 'nullable|integer|min:0',
        ];
    }
}
