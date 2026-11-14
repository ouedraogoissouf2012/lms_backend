<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ExplainsKnowledgeCheckAnswerRefusal;
use App\Rules\KnowledgeCheckCorrectAnswerAmongOptions;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valide l'update d'un knowledge check (PUT /api/knowledge-checks/{id}).
 * Autorisation propriétaire vérifiée dans KnowledgeCheckCrudService.
 *
 * Partie 3 : les options sont une liste de textes, et la bonne réponse désigne
 * une d'elles par son indice ({@see KnowledgeCheckCorrectAnswerAmongOptions},
 * 422 `correct_answer_not_among_options`). Les bornes de nombre et de longueur
 * des options suivront celles des évaluations quand QuestionOptionLimits
 * (#1069) sera fusionnée : dette tracée.
 */
final class UpdateKnowledgeCheckRequest extends FormRequest
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
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            // Une LISTE (D520) : indexées par des clés, les questions échappaient à la
            // règle qui lit la question voisine par son rang (questions.N.correct_answer).
            'questions' => 'sometimes|required|list|min:1',
            'questions.*.question' => 'required_with:questions|string',
            'questions.*.type' => 'required_with:questions|in:single,multiple,true_false',
            'questions.*.options' => 'required_with:questions|list|min:2',
            'questions.*.options.*' => 'string',
            'questions.*.correct_answer' => ['required_with:questions', new KnowledgeCheckCorrectAnswerAmongOptions],
            'passing_score' => 'nullable|integer|min:0|max:100',
            'max_attempts' => 'nullable|integer|min:1',
            'shuffle_questions' => 'nullable|boolean',
            'shuffle_options' => 'nullable|boolean',
            'show_correct_answers' => 'nullable|boolean',
            'show_explanation' => 'nullable|boolean',
            'time_limit_minutes' => 'nullable|integer|min:1',
            'position' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ];
    }

    /** Une bonne réponse hors des options porte son motif ; le reste, le rendu 422 commun. */
    protected function failedValidation(Validator $validator): void
    {
        $this->refuserLaBonneReponseHorsOptions($validator);

        parent::failedValidation($validator);
    }
}
