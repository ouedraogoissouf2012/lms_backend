<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Les évaluations qu'un élève voit, selon le type de son école (D72, E2a).
 *
 * - École créée depuis KLASSCI : la classe de `me/dashboard`, filtre
 *   `klassci_classe_id` ({@see KlassciLearnerEvaluationAudience}).
 * - École créée depuis le LMS : ses classes actives, filtre `classe_id`
 *   ({@see LocalLearnerEvaluationAudience}).
 *
 * Choisie à chaque appel par {@see LearnerEvaluationScope}, jamais au
 * constructeur (ADR-EI-01, règle 3).
 */
interface LearnerEvaluationAudience
{
    /**
     * @return Builder<Evaluation>|null  null : l'élève n'a pas de classe connue.
     */
    public function visibleFor(User $user): ?Builder;
}
