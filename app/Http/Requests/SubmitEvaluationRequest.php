<?php

namespace App\Http\Requests;

use Closure;
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
 * ## Format des réponses (MAP indexée par question_id)
 * Contrat aligné sur le frontend (`useTakeEvaluation.js`), sur le service de
 * correction (`EvaluationGradingService`) et sur le quiz (`SubmitQuizAttemptRequest`) :
 * `answers` est une MAP `{ "<question_id>": <réponse> }`. La réponse est une chaîne
 * (qcm / vrai_faux / reponse_courte / dissertation) ou un tableau de chaînes
 * (qcm_multiple). Une valeur vide (`""` ou `[]`) = question non répondue (0 point).
 *
 * Example request:
 * ```
 * POST /api/evaluations/123/submit
 * {
 *   "answers": {
 *     "1": "A",
 *     "2": "Faux",
 *     "3": "Short answer text",
 *     "4": ["A", "C"]
 *   },
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
    /** Longueur max d'une réponse (anti-DOS) — scalaire OU chaque élément d'un tableau. */
    private const MAX_ANSWER_LENGTH = 10000;

    /** Borne le nombre d'options cochées pour une question qcm_multiple (anti-DOS). */
    private const MAX_MULTIPLE_ANSWERS = 200;

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

        // Le quota de tentatives N'EST PLUS vérifié ici (#540).
        //
        // L'ancien Check 5 refusait dès qu'UNE soumission existait pour cet
        // étudiant, en ignorant `max_attempts`. Deux conséquences :
        //
        //   1. `POST /start` ouvrait volontiers la tentative 2 (200) et
        //      `POST /submit` la refusait ensuite en 403 « This action is
        //      unauthorized » — toute évaluation à `max_attempts > 1` était
        //      inutilisable de bout en bout ;
        //   2. le quota était exprimé à DEUX endroits avec deux règles
        //      différentes (ici « une seule soumission », dans le service
        //      « max_attempts »), ce qui est exactement la duplication qui
        //      avait déjà produit le 500 du parcours nominal.
        //
        // La règle vit désormais dans `EvaluationAttemptOpener`, source unique,
        // qui refuse en 403 avec le message métier « Nombre maximum de
        // tentatives atteint (N) ». Une FormRequest valide et autorise ; elle
        // ne porte pas de règle de gestion (§5).
        return true;
    }

    /**
     * Get the validation rules for evaluation submission.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // MAP indexée par question_id (cf. docblock). `min:1` : au moins une entrée.
            'answers' => [
                'required',
                'array',
                'min:1',
            ],
            // Chaque valeur : scalaire chaîne OU tableau de chaînes (qcm_multiple).
            // Vide (`''`/`[]`) toléré = non répondu. Types aberrants rejetés (422).
            'answers.*' => [$this->answerValueRule()],
            'submitted_at' => [
                'sometimes',
                'date_format:Y-m-d\\TH:i:s\\Z',
            ],
        ];
    }

    /**
     * Règle de validation d'une valeur de réponse (map `answers.*`).
     *
     * Accepte :
     *   - `''` / `[]` / `null` → question non répondue (0 point, légitime) ;
     *   - une chaîne ≤ MAX_ANSWER_LENGTH (qcm / vrai_faux / reponse_courte / dissertation) ;
     *   - un tableau de chaînes (qcm_multiple), borné en taille et en longueur.
     *
     * Rejette tout le reste (booléen, entier, objet imbriqué) → ferme le
     * type-juggling à la notation, cohérent avec le durcissement quiz (#498).
     * Rejette de fait l'ancien format LISTE `[{question_id, answer}]` : ses valeurs
     * sont des tableaux associatifs dont `question_id` est un entier (non-chaîne).
     */
    private function answerValueRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '' || $value === []) {
                return;
            }

            if (is_array($value)) {
                if (count($value) > self::MAX_MULTIPLE_ANSWERS) {
                    $fail('Trop de réponses sélectionnées pour cette question.');

                    return;
                }
                foreach ($value as $element) {
                    if (! $this->isValidScalarAnswer($element)) {
                        $fail('Chaque réponse doit être une chaîne de ' . self::MAX_ANSWER_LENGTH . ' caractères maximum.');

                        return;
                    }
                }

                return;
            }

            if (! $this->isValidScalarAnswer($value)) {
                $fail('Chaque réponse doit être une chaîne de ' . self::MAX_ANSWER_LENGTH . ' caractères maximum.');
            }
        };
    }

    /** Une réponse scalaire valide est une chaîne bornée en longueur. */
    private function isValidScalarAnswer(mixed $value): bool
    {
        return is_string($value) && mb_strlen($value) <= self::MAX_ANSWER_LENGTH;
    }

    /**
     * Custom error messages for evaluation submission.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'answers.required' => 'Au moins une réponse est requise',
            'answers.array' => 'Les réponses doivent être un objet indexé par identifiant de question',
            'answers.min' => 'Au moins une réponse est requise',
            'submitted_at.date_format' => 'La date de soumission doit être au format ISO 8601 (Y-m-d\\TH:i:s\\Z)',
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
        // If no submitted_at provided, use current time in strict ISO 8601 format (Y-m-d\TH:i:s\Z)
        if (!$this->has('submitted_at')) {
            $this->merge([
                'submitted_at' => now()->format('Y-m-d\\TH:i:s\\Z'),
            ]);
        }

        // Trim whitespace des réponses. `input('answers')` peut être une MAP
        // (contrat réel) ; on ne présume rien de sa forme (l'ancien `array_merge`
        // plantait sur les valeurs scalaires d'une map — cause du 500 #564).
        $answers = $this->input('answers');
        if (is_array($answers)) {
            $this->merge(['answers' => $this->trimAnswers($answers)]);
        }
    }

    /**
     * Trim défensif des réponses en préservant les clés (question_id).
     *
     * Les chaînes sont trimées ; les éléments chaîne d'un tableau (qcm_multiple)
     * sont trimés ; toute autre valeur est laissée telle quelle pour que la
     * validation (`answerValueRule`) puisse la rejeter proprement.
     *
     * @param  array<int|string, mixed>  $answers
     * @return array<int|string, mixed>
     */
    private function trimAnswers(array $answers): array
    {
        return collect($answers)->map(function ($value) {
            if (is_string($value)) {
                return trim($value);
            }
            if (is_array($value)) {
                return array_map(
                    static fn ($element) => is_string($element) ? trim($element) : $element,
                    $value
                );
            }

            return $value;
        })->all();
    }
}
