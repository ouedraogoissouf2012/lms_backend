<?php

declare(strict_types=1);

namespace App\Support\Evaluation;

/**
 * Source unique des bornes des options d'une question d'évaluation (B5).
 *
 * Avant, `questions.*.options` n'avait aucune borne : une seule requête de création ou
 * de mise à jour pouvait écrire une liste d'options de taille arbitraire. Les deux
 * requêtes (`StoreEvaluationRequest`, `UpdateEvaluationRequest`) lisent ces deux
 * nombres ici, et la spec (`docs/openapi.yaml`, corps de `PUT /evaluations/{id}`) les
 * recopie en `maxItems` et `maxLength`.
 *
 * - 26 options : la limite que l'éditeur du front suppose déjà (étiquettes A à Z), et
 *   sous les 200 cases cochées de la remise (`SubmitEvaluationRequest`).
 * - 1 000 caractères : la borne du texte d'une question (`questions.*.question`),
 *   très en dessous des 10 000 caractères d'une réponse. Une réponse de QCM est le
 *   texte de l'option : une option plus longue ne pourrait jamais être remise.
 */
final class QuestionOptionLimits
{
    /** @var int */
    public const MAX_OPTIONS = 26;

    /** @var int */
    public const MAX_OPTION_LENGTH = 1000;

    /** La règle de la liste : `options` reste facultatif, une liste, de 26 au plus. */
    public static function listRule(): string
    {
        return 'nullable|list|max:'.self::MAX_OPTIONS;
    }

    /** La règle de chaque option : un texte de 1 000 caractères au plus. */
    public static function itemRule(): string
    {
        return 'string|max:'.self::MAX_OPTION_LENGTH;
    }
}
