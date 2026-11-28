<?php

declare(strict_types=1);

namespace App\Support\Evaluation;

use App\Rules\CorrectAnswersAmongOptions;
use App\Rules\CorrectAnswersCardinality;
use App\Rules\TrueFalseOptions;

/**
 * Les règles d'UNE question à correction (texte, type, points, options, bonnes réponses), en un
 * seul lieu : la création et la modification d'une évaluation (`questions.N.…`), et, depuis
 * #1702, la banque de questions (une question à la racine de la requête). Extraites des deux
 * requêtes d'évaluation, à l'identique.
 *
 * - Les options : une liste de textes bornée (B5 : 26 options, 1 000 caractères), un vrai_faux
 *   exactement `['Vrai', 'Faux']` (D532).
 * - Les bonnes réponses : des textes bornés comme les options, parmi les options d'une question
 *   à options, au plus une pour une réponse unique (D466).
 */
final class ReglesDUneQuestion
{
    /**
     * @param  string  $prefixe  `questions.*.` pour une liste, `''` pour une question à la racine
     * @return array<string, mixed>
     */
    public static function pour(string $prefixe): array
    {
        return [
            $prefixe.'question' => 'required|string|max:1000',
            $prefixe.'type' => 'required|in:qcm,qcm_multiple,vrai_faux,reponse_courte,dissertation',
            $prefixe.'points' => 'nullable|numeric|min:0',
            $prefixe.'options' => ['nullable', 'list', 'max:'.QuestionOptionLimits::MAX_OPTIONS, new TrueFalseOptions],
            $prefixe.'options.*' => QuestionOptionLimits::itemRule(),
            $prefixe.'correct_answers' => ['nullable', 'list', 'max:'.QuestionOptionLimits::MAX_OPTIONS, new CorrectAnswersCardinality, new CorrectAnswersAmongOptions],
            $prefixe.'correct_answers.*' => QuestionOptionLimits::itemRule(),
        ];
    }
}
