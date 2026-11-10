<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Evaluation\Programming\EvaluationProgramming;
use App\Services\Evaluation\Programming\KlassciEvaluationProgramming;
use App\Services\Evaluation\Programming\LocalEvaluationProgramming;
use App\Services\Roster\RosterAuthorityFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Les sources des évaluations, une par type d'école (D72, ADR-EI-01).
 *
 * Un fournisseur à part du catalogue : une évaluation n'est pas une donnée du
 * catalogue pédagogique, et ses ports grandiront avec E2 et E3 (l'audience de
 * l'apprenant, la fenêtre, le roster des résultats).
 *
 * Même règle que les sources du catalogue : `bind()`, résolu à chaque
 * demande, jamais `singleton()` (il rejouerait le premier tenant pour tous les
 * suivants). Le seul endroit qui compare le mode reste
 * `RosterAuthorityFactory::sourceForCurrentTenant`.
 */
final class EvaluationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            EvaluationProgramming::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)
                ->sourceForCurrentTenant(KlassciEvaluationProgramming::class, LocalEvaluationProgramming::class),
        );
    }
}
