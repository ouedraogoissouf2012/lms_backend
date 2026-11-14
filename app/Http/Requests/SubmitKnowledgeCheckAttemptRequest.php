<?php

namespace App\Http\Requests;

use App\Rules\OptionIndexAnswer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validates knowledge check submission (POST /api/knowledge-checks/{id}/submit).
 *
 * ## Purpose
 * Student submits answers to knowledge check quiz.
 * Validates answer format and time tracking.
 *
 * ## Authorization Model
 * Authenticated students only (auth:sanctum).
 * Answers are marked with user_id automatically.
 *
 * ## 10-year Consideration
 * Answers array indexed by question index (match question order in DB).
 * Time spent (seconds) used for analytics, not enforcement.
 * If question shuffling changes, validate answer mapping separately.
 *
 * ## C45 — une réponse est un indice d'option
 * Chaque réponse est `null`, un entier ≥ 0, ou une liste d'entiers ≥ 0
 * distincts ({@see OptionIndexAnswer}) : le seul format que le front envoie.
 * Une autre forme est refusée avant toute écriture, avec son motif
 * (`answer_not_an_option_index`, ADR-906-01).
 */
final class SubmitKnowledgeCheckAttemptRequest extends FormRequest
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
            'answers' => 'required|array',
            'answers.*' => ['nullable', new OptionIndexAnswer],
            'time_spent_seconds' => 'required|integer|min:0',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'answers.required' => 'Les réponses sont requises',
            'answers.array' => 'Les réponses doivent être un tableau',
            'time_spent_seconds.required' => 'Le temps passé est requis',
            'time_spent_seconds.integer' => 'Le temps doit être un entier',
            'time_spent_seconds.min' => 'Le temps doit être positif ou zéro',
        ];
    }

    /**
     * Une réponse qui n'est pas un indice porte son motif ; les autres erreurs
     * gardent le rendu 422 commun (`bootstrap/app.php`).
     */
    protected function failedValidation(Validator $validator): void
    {
        foreach ($validator->failed() as $regles) {
            if (array_key_exists(OptionIndexAnswer::class, $regles)) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => OptionIndexAnswer::MESSAGE,
                    'reason' => 'answer_not_an_option_index',
                    'errors' => $validator->errors()->toArray(),
                ], 422));
            }
        }

        parent::failedValidation($validator);
    }
}
