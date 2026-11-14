<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSource;
use Illuminate\Database\Eloquent\Builder;

/**
 * École créée depuis le LMS : l'élève voit les évaluations de TOUTES ses
 * classes où son inscription est active (D72, E2a, Q1). La classe se lit sur
 * l'évaluation (`classe_id`), jamais sur un « dashboard » : aucun appel à
 * KLASSCI, même pour un compte porteur d'un jeton.
 */
final class LocalLearnerEvaluationAudience implements LearnerEvaluationAudience
{
    public function __construct(private readonly EnrollmentSource $inscriptions) {}

    /**
     * Jamais null : sans classe active, une requête vide.
     *
     * @return Builder<Evaluation>
     */
    public function visibleFor(User $user): Builder
    {
        $classes = $this->inscriptions->localClasseIdsFor($user);

        $query = EvaluationsOuvertesAuxEleves::de($user->institution_id);

        // Sans classe active, la liste est vide, et non « classe introuvable » :
        // c'est un état normal d'un élève pas encore inscrit.
        return $classes === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('classe_id', $classes);
    }
}
