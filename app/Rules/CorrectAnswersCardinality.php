<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Concerns\ReadsSiblingQuestion;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Le nombre MAXIMUM de bonnes réponses, selon le type de la question (D466).
 *
 * - `qcm`, `vrai_faux` : au plus une, la réponse UNIQUE que l'éditeur du front enregistre.
 * - `qcm_multiple` : sans doublon. La notation compare l'ensemble remis, trié, à l'ensemble
 *   attendu (`EvaluationGradingService::isCorrectAnswer`) : un doublon le rendrait impossible.
 *
 * Elle n'impose pas de MINIMUM : une question sans bonne réponse reste enregistrable (un
 * brouillon). L'obligation appartient à la publication.
 */
final class CorrectAnswersCardinality implements DataAwareRule, ValidationRule
{
    use ReadsSiblingQuestion;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $type = $this->questionDe($attribute)['type'] ?? null;
        if (! is_array($value)) {
            return;
        }

        if (in_array($type, ['qcm', 'vrai_faux'], true) && count($value) > 1) {
            $fail('Une question à réponse unique n\'a qu\'une bonne réponse.');

            return;
        }

        if ($type === 'qcm_multiple' && count($value) !== count(array_unique($value, SORT_REGULAR))) {
            $fail('Une bonne réponse ne peut pas figurer deux fois.');
        }
    }
}
