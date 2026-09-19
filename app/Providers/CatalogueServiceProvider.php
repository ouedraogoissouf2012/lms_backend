<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Catalogue\CatalogueAuthority;
use App\Services\Roster\RosterAuthorityFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Liaison de l'autorité sur le catalogue pédagogique (#848).
 *
 * Fournisseur dédié, comme `RosterServiceProvider` et `RetentionServiceProvider`
 * avant lui. Ce n'est pas un choix de style : `AppServiceProvider` mesure 299
 * lignes pour une garde à 300, et son propre docblock rappelle que la CI a déjà
 * refusé une liaison de cinq lignes ajoutée là.
 *
 * @see docs/adr/2026-09-18-848-01-catalogue-pedagogique-local.md
 */
final class CatalogueServiceProvider extends ServiceProvider
{
    /**
     * `bind()` et JAMAIS `singleton()`.
     *
     * La closure est évaluée À LA RÉSOLUTION, donc après `ResolveInstitution`.
     * Un singleton capturerait le premier tenant résolu et le rejouerait pour
     * tous les suivants : sous Octane comme en suite PHPUnit, le conteneur
     * survit entre requêtes, et une fuite de singleton ici laisserait un
     * établissement KLASSCI écrire un catalogue local — pas un défaut de
     * performance, une fuite entre établissements (épique #697, art. 3).
     */
    public function register(): void
    {
        $this->app->bind(
            CatalogueAuthority::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)->catalogueForCurrentTenant(),
        );
    }
}
