<?php

declare(strict_types=1);

namespace App\Support\Evaluation;

/**
 * Source unique de la borne du NOMBRE de questions (B5, partie 2), sœur de
 * {@see QuestionOptionLimits}.
 *
 * Avant, `questions` n'avait aucune borne de nombre. Une mise à jour d'évaluation
 * supprime puis recrée toutes ses questions (`EvaluationUpdateService`) : une seule
 * requête pouvait écrire des milliers de lignes, sous la seule borne de
 * `post_max_size`. La spec (`docs/openapi.yaml`, corps de `PUT /evaluations/{id}`)
 * recopie le nombre en `maxItems`.
 *
 * 200 : la borne voisine du dépôt (`SubmitEvaluationRequest::MAX_MULTIPLE_ANSWERS`).
 * Un contrôle dépasse rarement 100 questions ; 200 garde une marge de deux. Si la
 * production en compte davantage, la borne monte au-dessus du maximum mesuré.
 */
final class QuestionCountLimit
{
    /** @var int */
    public const MAX_QUESTIONS = 200;

    /** La borne à ajouter à la règle de la liste `questions`. */
    public static function maxRule(): string
    {
        return 'max:'.self::MAX_QUESTIONS;
    }
}
