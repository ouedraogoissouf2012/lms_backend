<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Roster\RosterAuthorityFactory;
use App\Services\Seances\Mutations\VisioToggleService;
use App\Services\Visio\Lifecycle\LocalVisioActivation;
use App\Services\Visio\Lifecycle\LocalVisioToggle;
use App\Services\Visio\Lifecycle\VisioActivationService;
use App\Services\Visio\Lifecycle\VisioActivationSource;
use App\Services\Visio\Lifecycle\VisioToggleSource;
use Illuminate\Support\ServiceProvider;

/**
 * Les sources de la visio, une par type d'école (ADR-EI-01).
 *
 * Un fournisseur à part, comme `EvaluationServiceProvider` : la visio n'est pas
 * une donnée du catalogue pédagogique, et `AppServiceProvider` est à la limite
 * de sa garde de taille.
 *
 * Même règle que les autres sources : `bind()`, résolu à chaque demande, jamais
 * `singleton()` (il rejouerait le premier tenant pour tous les suivants). Le seul
 * endroit qui compare le mode reste `RosterAuthorityFactory::sourceForCurrentTenant`.
 */
final class VisioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            VisioActivationSource::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)
                ->sourceForCurrentTenant(VisioActivationService::class, LocalVisioActivation::class),
        );

        $this->app->bind(
            VisioToggleSource::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)
                ->sourceForCurrentTenant(VisioToggleService::class, LocalVisioToggle::class),
        );
    }
}
