<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates evaluation submission request (POST /api/evaluations/{id}/submit).
 *
 * ## Purpose
 * Validates and authorizes evaluation answer submission with:
 * - Student authenticated (not teacher/coordinator)
 * - Evaluation published (not draft)
 * - Deadline not passed
 * - Not already submitted
 * - Answers present and valid for questions
 *
 * ## Data Structure
 * Evaluation = quiz/test with questions
 * Each question has type: multiple_choice, true_false, short_answer, essay
 * Submission = student answers one or more questions
 *
 * Example request:
 * ```
 * POST /api/evaluations/123/submit
 * {
 *   "answers": [
 *     {"question_id": 1, "answer": "A"},
 *     {"question_id": 2, "answer": "False"},
 *     {"question_id": 3, "answer": "Short answer text"}
 *   ],
 *   "submitted_at": "2026-04-30T14:30:00Z"
 * }
 * ```
 *
 * ## 10-year perspective
 * If evaluation format changes (e.g., add multimedia answers),
 * update rules() and tests. All submissions validated consistently.
 *
 * Authorization flow:
 * 1. User must be authenticated (auth:sanctum middleware)
 * 2. User must be student (not teacher)
 * 3. Evaluation must be published
 * 4. Deadline must not be passed
 * 5. Student must not have already submitted
 */
final class SubmitEvaluationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Complex authorization: Check evaluation state + deadline + prior submission
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = auth()->user();

        // Check 1: User must be authenticated
        if (!$user) {
            return false;
        }

        // Check 2: Only students can submit evaluations (not teachers)
        if (!$user->isStudent()) {
            return false;
        }

        // Check 3: Evaluation must exist and be published
        // Route parameter is 'id' (POST /api/evaluations/{id}/submit)
        $evaluation = \App\Models\Evaluation::where('id', $this->route('id'))
            ->where('is_published', true)
            ->first();

        if (!$evaluation) {
            return false;
        }

        // Check 4: Deadline must not be passed (if deadline exists)
        if ($evaluation->deadline_at && now()->isAfter($evaluation->deadline_at)) {
            return false;
        }

        // Check 5: Student must not have already submitted
        $previousSubmission = \App\Models\EvaluationSubmission::where('evaluation_id', $evaluation->id)
            ->where('student_id', $user->id)
            ->exists();

        if ($previousSubmission) {
            return false;
        }

        return true;
    }

    /**
     * Get the validation rules for evaluation submission.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'answers' => [
                'required',
                'array',
                'min:1', // At least one answer
            ],
            'answers.*.question_id' => [
                'required',
                'integer',
                'exists:questions,id',
            ],
            'answers.*.answer' => [
                'required',
                'string',
                'max:10000', // Max 10KB per answer (prevent DOS)
            ],
            'submitted_at' => [
                'sometimes',
                'date_format:Y-m-d\TH:i:s\Z',
            ],
        ];
    }

    /**
     * Custom error messages for evaluation submission.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'answers.required' => 'Au moins une réponse est requise',
            'answers.array' => 'Les réponses doivent être un tableau',
            'answers.min' => 'Au moins une réponse est requise',
            'answers.*.question_id.required' => 'L\'ID de la question est requis pour chaque réponse',
            'answers.*.question_id.exists' => 'La question n\'existe pas',
            'answers.*.answer.required' => 'Une réponse est requise pour chaque question',
            'answers.*.answer.max' => 'Chaque réponse ne doit pas dépasser 10000 caractères',
            'submitted_at.date_format' => 'La date de soumission doit être au format ISO 8601 (Y-m-d\TH:i:s\Z)',
        ];
    }

    /**
     * Prepare data for validation.
     *
     * Normalize submission:
     * - Set submitted_at to now if not provided
     * - Trim answer text
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // If no submitted_at provided, use current time
        if (!$this->has('submitted_at')) {
            $this->merge([
                'submitted_at' => now()->toIso8601String(),
            ]);
        }

        // Trim whitespace from all answers
        $answers = collect($this->answers ?? [])->map(function ($answer) {
            return array_merge($answer, [
                'answer' => trim($answer['answer'] ?? ''),
            ]);
        })->toArray();

        $this->merge(['answers' => $answers]);
    }
}
