<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ce qu'un élève peut voir des évaluations : SOURCE UNIQUE de deux lectures,
 * pour qu'elles ne divergent jamais.
 * - La liste de l'élève (StudentEvaluationsListService).
 * - La lecture d'une évaluation par un apprenant
 *   (EvaluationListService::findOne) : l'écran de passage s'ouvre pour
 *   EXACTEMENT ce que la liste propose.
 *
 * La classe vient de la source VIVE, `me/dashboard` avec le jeton de l'élève,
 * et non du cache `user_classes` : celui-ci ne s'écrit qu'au login et peut
 * manquer pour un élève connecté (#969). La réponse est mise en cache par jeton
 * (KlassciProxyService::requestWithUserToken, GET,
 * `user_token_cache_default_ttl`) : la lecture qui suit la liste relit la même
 * entrée. Une indisponibilité de KLASSCI n'est pas avalée :
 * KlassciUnavailableException remonte (503 canonique, bootstrap/app.php).
 *
 * Une évaluation ne porte que `klassci_classe_id` : une évaluation locale
 * (colonne nulle) n'est visible d'aucun élève, et un élève d'école autonome n'a
 * aujourd'hui aucun écran d'évaluation (#805).
 */
final class LearnerEvaluationScope
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
     * Les évaluations qu'un élève de cette classe, dans cet établissement, voit :
     * publiées, d'un statut visible (le brouillon exclu), avec au moins une
     * question.
     *
     * L'établissement est filtré EXPLICITEMENT : la source unique porte le
     * tenant, sans s'en remettre au scope global, ouvert par défaut quand aucun
     * tenant n'est posé. Sans établissement : rien (`1 = 0`, sans `IS NULL` qui
     * remonterait les lignes orphelines).
     *
     * @return Builder<Evaluation>
     */
    public function visibleIn(int $klassciClasseId, ?int $institutionId): Builder
    {
        $query = Evaluation::query();

        if ($institutionId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('institution_id', $institutionId)
            ->where('klassci_classe_id', $klassciClasseId)
            ->where('is_published', true)
            ->whereIn('status', [
                EvaluationStatus::Planifiee->value,
                EvaluationStatus::EnCours->value,
                EvaluationStatus::Terminee->value,
            ])
            ->whereHas('questions');
    }

    /**
     * Les évaluations que CET élève voit, ou null s'il n'a pas de classe
     * (pas de jeton KLASSCI, ou aucune classe chez KLASSCI).
     *
     * @return Builder<Evaluation>|null
     */
    public function visibleFor(User $user): ?Builder
    {
        $classe = $this->classeOf($user);
        if ($classe === null || ! is_numeric($classe['id'] ?? null)) {
            return null;
        }

        return $this->visibleIn((int) $classe['id'], $user->institution_id);
    }

    /** L'élève voit-il cette évaluation dans SA liste ? */
    public function includes(User $user, Evaluation $evaluation): bool
    {
        return $this->visibleFor($user)?->whereKey($evaluation->getKey())->exists() ?? false;
    }
}
