<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Concerns\ReadsSiblingQuestion;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Les bonnes réponses d'une question à options sont parmi ses options (D466).
 *
 * Pour `qcm`, `qcm_multiple` et `vrai_faux`, l'élève ne remet que le TEXTE d'une option
 * affichée, et la notation le compare à `correct_answers`
 * (`EvaluationGradingService::isCorrectAnswer`). Une bonne réponse absente des options rend
 * la question impossible à réussir. La comparaison est stricte, texte contre texte.
 *
 * Elle n'impose AUCUNE bonne réponse (une liste vide ou nulle passe : un brouillon), et laisse
 * le type des éléments et les options mal formées à leurs propres règles. Son échec porte un
 * motif, posé par la requête (`ExplainsCorrectAnswersRefusal`).
 */
final class CorrectAnswersAmongOptions implements DataAwareRule, ValidationRule
{
    use ReadsSiblingQuestion;

    /** Les types dont les bonnes réponses sont des options. */
    public const TYPES_A_OPTIONS = ['qcm', 'qcm_multiple', 'vrai_faux'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $question = $this->questionDe($attribute);
        if ($question === null || ! in_array($question['type'] ?? null, self::TYPES_A_OPTIONS, true)) {
            return;
        }

        $options = $question['options'] ?? null;
        if (! is_array($value) || ! is_array($options) || ! array_is_list($options)) {
            return;
        }

        foreach ($value as $bonne) {
            if (is_string($bonne) && ! in_array($bonne, $options, true)) {
                $fail('Une bonne réponse doit être une des options proposées.');

                return;
            }
        }
    }
}
