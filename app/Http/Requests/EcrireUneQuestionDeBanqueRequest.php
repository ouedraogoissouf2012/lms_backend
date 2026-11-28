<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ExplainsCorrectAnswersRefusal;
use App\Support\Evaluation\ReglesDUneQuestion;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * #1702 (D1) : une question de la banque, validée comme une question d'évaluation
 * ({@see ReglesDUneQuestion}), avec le même refus motivé d'une bonne réponse hors des options
 * (`correct_answer_not_among_options`). Qui écrit est décidé par `BanqueDeQuestions`.
 */
final class EcrireUneQuestionDeBanqueRequest extends FormRequest
{
    use ExplainsCorrectAnswersRefusal;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ReglesDUneQuestion::pour('') + [
            'explanation' => 'nullable|string|max:2000',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->refuserLesBonnesReponsesHorsOptions($validator);

        parent::failedValidation($validator);
    }

    /** @return array<string, mixed> */
    public function champs(): array
    {
        return $this->validated();
    }
}
