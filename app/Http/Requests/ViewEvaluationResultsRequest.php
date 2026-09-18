<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksEvaluationOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Autorise la LECTURE des notes d'une évaluation.
 *
 * Deux routes livrent la même donnée — les notes nominatives des étudiants :
 * `GET /evaluations/{id}/results-by-class` et `GET /evaluations/{id}/submissions`.
 * Aucune ne vérifiait à qui appartient l'évaluation : le rôle suffisait, donc
 * tout enseignant du tenant lisait les notes de tout collègue. Mesuré avant
 * correction : `/submissions` rendait 200 à un enseignant tiers, et les deux
 * routes rendaient 200 à un enseignant sans identité KLASSCI établie.
 *
 * ## Une seule classe pour les deux routes
 *
 * Elles exposent la même donnée et méritent donc la même règle. Deux classes
 * jumelles, c'est la garantie qu'un jour l'une divergera de l'autre — et la
 * fuite reviendrait par celle qu'on aura oublié de suivre.
 *
 * ## Pourquoi un FormRequest plutôt qu'un contrôle dans le service
 *
 * C'est la convention du domaine ({@see DeleteEvaluationRequest},
 * {@see PublishEvaluationRequest}, {@see UpdateEvaluationRequest},
 * {@see GradeEvaluationSubmissionRequest}), et l'autorisation s'exécute AVANT le
 * contrôleur : un appelant non autorisé n'atteint plus KLASSCI. Le refus ne
 * dépend donc plus de la disponibilité d'un tiers.
 *
 * @see app/Http/Requests/Concerns/ChecksEvaluationOwnership.php
 * @see tests/Feature/Security/EvaluationResultsOwnershipTest.php
 */
final class ViewEvaluationResultsRequest extends FormRequest
{
    use ChecksEvaluationOwnership;

    public function authorize(): bool
    {
        return $this->checkEvaluationReadAccess();
    }

    /**
     * Aucune règle : la requête ne porte que l'identifiant de route, déjà
     * contraint par le patron de la route et résolu par le contrôle ci-dessus.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
