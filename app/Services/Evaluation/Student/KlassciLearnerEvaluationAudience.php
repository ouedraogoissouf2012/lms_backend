<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Database\Eloquent\Builder;

/**
 * École créée depuis KLASSCI : ce qu'un élève voit des évaluations.
 *
 * La classe vient de la source VIVE, `me/dashboard` avec le jeton de l'élève,
 * et non du cache `user_classes` : celui-ci ne s'écrit qu'au login et peut
 * manquer pour un élève connecté (#969). La réponse est mise en cache par jeton
 * (KlassciProxyService::requestWithUserToken, GET,
 * `user_token_cache_default_ttl`) : la lecture qui suit la liste relit la même
 * entrée. Une indisponibilité de KLASSCI n'est pas avalée :
 * KlassciUnavailableException remonte (503 canonique, bootstrap/app.php).
 *
 * Extrait de `LearnerEvaluationScope` (D72, E2a), sans changement de
 * comportement.
 */
final class KlassciLearnerEvaluationAudience implements LearnerEvaluationAudience
{
    public function __construct(private readonly KlassciProxyService $klassciService) {}

    /**
     * La classe KLASSCI de l'élève.
     *
     * @return array<string, mixed>|null  `id` numérique ; null sans jeton
     *                                    KLASSCI ou sans classe.
     */
    public function classeOf(User $user): ?array
    {
        $jeton = $user->klassci_token;
        if (! is_string($jeton) || $jeton === '') {
            return null;
        }

        $donnees = $this->klassciService->requestWithUserToken($jeton, 'me/dashboard', 'GET')['data'] ?? null;
        $classe = is_array($donnees) ? ($donnees['classe'] ?? null) : null;

        if (! is_array($classe) || ! is_numeric($classe['id'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $classe */
        return $classe;
    }

    /**
     * Les évaluations qu'un élève de cette classe KLASSCI, dans cet
     * établissement, voit.
     *
     * @return Builder<Evaluation>
     */
    public function visibleIn(int $klassciClasseId, ?int $institutionId): Builder
    {
        return EvaluationsOuvertesAuxEleves::de($institutionId)->where('klassci_classe_id', $klassciClasseId);
    }

    public function visibleFor(User $user): ?Builder
    {
        $classe = $this->classeOf($user);
        if ($classe === null || ! is_numeric($classe['id'] ?? null)) {
            return null;
        }

        return $this->visibleIn((int) $classe['id'], $user->institution_id);
    }
}
