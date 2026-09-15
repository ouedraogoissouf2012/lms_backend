<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Roster\RosterAuthority;
use App\Services\Roster\RosterAuthorityFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Liaison de l'autorité sur le roster (#805).
 *
 * Fournisseur dédié, comme `RateLimitServiceProvider` ou `RetentionServiceProvider`
 * l'ont déjà fait pour leur préoccupation. Deux raisons, et la seconde est
 * mesurée : `AppServiceProvider` était à 299 lignes pour une garde à 300, et y
 * ajouter cette liaison l'a fait passer à 322 — la CI l'a refusé, à juste titre.
 */
final class RosterServiceProvider extends ServiceProvider
{
    /**
     * `bind()` et JAMAIS `singleton()`.
     *
     * La closure est évaluée À LA RÉSOLUTION, donc après `ResolveInstitution`.
     * Un singleton capturerait le premier tenant résolu et le rejouerait pour
     * tous les suivants : sous Octane comme en suite PHPUnit, le conteneur
     * survit entre requêtes, et une fuite de singleton ici serait une fuite
     * entre établissements — pas un défaut de performance (épique #697, art. 3).
     */
    public function register(): void
    {
        $this->app->bind(
            RosterAuthority::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)->forCurrentTenant(),
        );
    }
}
