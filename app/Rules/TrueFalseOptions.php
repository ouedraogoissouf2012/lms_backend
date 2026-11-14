<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Concerns\ReadsSiblingQuestion;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Une question `vrai_faux` d'évaluation a EXACTEMENT les options ['Vrai', 'Faux'] (D532).
 *
 * La page de passage et le corrigé proposent toujours ces deux textes (la constante
 * `OPTIONS_VRAI_FAUX` du front), quelles que soient les options enregistrées, et l'élève
 * remet ce texte exact. D'autres options laissaient enregistrer une bonne réponse que
 * l'écran ne propose jamais : la question comptait 0 pour tous. L'ordre et la casse
 * comptent (D544) : la forme est canonique, égale à la constante du front.
 *
 * IMPLICITE : elle s'applique aussi à des options nulles ou absentes, que le `nullable` de
 * la liste ferait sinon passer. Les autres types ne sont pas touchés. Son échec porte le
 * motif `correct_answer_not_among_options` (`ExplainsCorrectAnswersRefusal`) : c'est le
 * même concept, une bonne réponse qui ne désigne rien de ce que l'écran propose.
 */
final class TrueFalseOptions implements DataAwareRule, ValidationRule
{
    use ReadsSiblingQuestion;

    /** Les deux options, dans l'ordre que propose l'écran. */
    public const OPTIONS = ['Vrai', 'Faux'];

    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $question = $this->questionDe($attribute);
        if ($question === null || ($question['type'] ?? null) !== 'vrai_faux') {
            return;
        }

        if ($value !== self::OPTIONS) {
            $fail('Une question vrai/faux propose exactement « Vrai » et « Faux ».');
        }
    }
}
