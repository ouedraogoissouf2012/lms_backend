<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Une réponse à une question de quiz de leçon est un INDICE d'option (C45).
 *
 * Le front range la bonne réponse et remet le choix de l'élève par indice
 * (`QuestionOptionsEditor.vue`, `useKnowledgeCheckPlayer.js`) ; la notation
 * compare en strict (`KnowledgeCheckGradingService::gradeAnswer`). Un texte, un
 * booléen ou un décimal n'y est donc jamais juste : il est refusé au lieu d'être
 * noté faux en silence.
 *
 * Admis : un entier ≥ 0, ou une liste d'entiers ≥ 0 distincts (`multiple`, la
 * liste vide valant « non répondue »). `null` relève du `nullable` de la règle
 * voisine. Les bornes (l'indice existe-t-il parmi les options affichées ?) ne
 * sont pas lues ici : elles dépendent de l'ordre présenté à l'élève.
 */
final class OptionIndexAnswer implements ValidationRule
{
    public const MESSAGE = 'Une réponse est l\'indice d\'une option, ou une liste d\'indices distincts.';

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::estUnIndice($value)) {
            return;
        }

        if (is_array($value) && array_is_list($value)
            && array_filter($value, self::estUnIndice(...)) === $value
            && count(array_unique($value)) === count($value)) {
            return;
        }

        $fail(self::MESSAGE);
    }

    private static function estUnIndice(mixed $valeur): bool
    {
        return is_int($valeur) && $valeur >= 0;
    }
}
