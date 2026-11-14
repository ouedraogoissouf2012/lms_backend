<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\User;
use App\Services\Roster\RosterAuthorityFactory;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ce qu'un élève peut voir des évaluations : SOURCE UNIQUE des lectures de
 * l'apprenant, pour qu'elles ne divergent jamais.
 * - La lecture d'une évaluation par un apprenant
 *   (EvaluationListService::findOne) : l'écran de passage s'ouvre pour
 *   EXACTEMENT ce que la liste propose.
 * - Le démarrage d'une tentative (EvaluationAttemptStateService) et la page
 *   matière (MatiereEvaluationsFetcher).
 *
 * D72, E2a : la source dépend du type d'école ({@see LearnerEvaluationAudience}).
 * Elle est résolue À CHAQUE APPEL par la fabrique, jamais au constructeur : ce
 * service est reçu par le constructeur de services que les contrôleurs, gardés
 * sur l'objet Route, mémoïsent (ADR-EI-01, règle 3 ; précédent
 * `LessonListService:213`). `AudienceResolueAChaqueAppelTest` le prouve.
 */
final class LearnerEvaluationScope
{
    public function __construct(private readonly RosterAuthorityFactory $sources) {}

    /**
     * Les évaluations que CET élève voit, ou null s'il n'a pas de classe
     * connue (école créée depuis KLASSCI : pas de jeton, ou aucune classe).
     *
     * @return Builder<Evaluation>|null
     */
    public function visibleFor(User $user): ?Builder
    {
        return $this->sources
            ->sourceForCurrentTenant(KlassciLearnerEvaluationAudience::class, LocalLearnerEvaluationAudience::class)
            ->visibleFor($user);
    }

    /** L'élève voit-il cette évaluation dans SA liste ? */
    public function includes(User $user, Evaluation $evaluation): bool
    {
        return $this->visibleFor($user)?->whereKey($evaluation->getKey())->exists() ?? false;
    }
}
