<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ExplainsKnowledgeCheckAnswerRefusal;
use App\Rules\KnowledgeCheckCorrectAnswerAmongOptions;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valide la création d'un knowledge check (POST /api/knowledge-checks).
 * Autorisation propriétaire chapitre vérifiée dans KnowledgeCheckCrudService.
 *
 * Partie 3 : les options sont une liste de textes, et la bonne réponse désigne
 * une d'elles par son indice ({@see KnowledgeCheckCorrectAnswerAmongOptions},
 * 422 `correct_answer_not_among_options`). Les bornes de nombre et de longueur
 * des options suivront celles des évaluations quand QuestionOptionLimits
 * (#1069) sera fusionnée : dette tracée.
 */
final class StoreKnowledgeCheckRequest extends FormRequest
{
    use ExplainsKnowledgeCheckAnswerRefusal;

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
            'questions.*.options' => 'required|list|min:2',
            'questions.*.options.*' => 'string',
            'questions.*.correct_answer' => ['required', new KnowledgeCheckCorrectAnswerAmongOptions],
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

    /** Une bonne réponse hors des options porte son motif ; le reste, le rendu 422 commun. */
    protected function failedValidation(Validator $validator): void
    {
        $this->refuserLaBonneReponseHorsOptions($validator);

        parent::failedValidation($validator);
    }
}
