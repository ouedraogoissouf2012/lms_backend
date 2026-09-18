<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Classe\ClasseRoster;
use App\Services\Classe\KlassciEnvelopeClasseRoster;
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

        // Qui sont les etudiants d'une classe (#841). Le roster se lit dans
        // l'enveloppe `classes/{id}` et jamais via `classes/{id}/etudiants`, que
        // KLASSCI refuse PAR CLASSE a tous les roles (#669).
        //
        // Ce fournisseur plutot qu'AppServiceProvider : le docblock ci-dessus a
        // deja paye ce prix une fois. Mes cinq lignes l'avaient refait passer de
        // 299 a 304 pour une garde a 300, et la CI les a refusees.
        $this->app->bind(ClasseRoster::class, KlassciEnvelopeClasseRoster::class);
    }
}
