<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Evaluation\Programming\EvaluationProgramming;
use App\Services\Evaluation\Programming\KlassciEvaluationProgramming;
use App\Services\Evaluation\Programming\LocalEvaluationProgramming;
use App\Services\Evaluation\Student\KlassciMatieresDesNotes;
use App\Services\Evaluation\Student\LocalMatieresDesNotes;
use App\Services\Evaluation\Student\LocalStudentEvaluationsReader;
use App\Services\Evaluation\Student\MatieresDesNotes;
use App\Services\Evaluation\Student\StudentEvaluationsListService;
use App\Services\Evaluation\Student\StudentEvaluationsReader;
use App\Services\Evaluation\Teacher\EvaluationResultsReader;
use App\Services\Evaluation\Teacher\LocalEvaluationResultsReader;
use App\Services\Evaluation\Teacher\TeacherEvaluationResultsService;
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

        // D72, E2a : la liste de l'élève.
        $this->app->bind(
            StudentEvaluationsReader::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)
                ->sourceForCurrentTenant(StudentEvaluationsListService::class, LocalStudentEvaluationsReader::class),
        );

        // D72, E3 : les résultats de l'enseignant, par élève de la classe.
        $this->app->bind(
            EvaluationResultsReader::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)
                ->sourceForCurrentTenant(TeacherEvaluationResultsService::class, LocalEvaluationResultsReader::class),
        );

        // D72, E4a : les matières de « Mes notes ».
        $this->app->bind(
            MatieresDesNotes::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)
                ->sourceForCurrentTenant(KlassciMatieresDesNotes::class, LocalMatieresDesNotes::class),
        );
    }
}
