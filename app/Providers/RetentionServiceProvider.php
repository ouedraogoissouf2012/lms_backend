<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Chapter\ChapterRetentionService;
use App\Services\Retention\Policies\AuditLogsPolicy;
use App\Services\Retention\Policies\SeanceRecordingsPolicy;
use App\Services\Retention\Policies\SoftDeletedInstitutionsPolicy;
use App\Services\Retention\Policies\SoftDeletedUsersPolicy;
use App\Services\Retention\Policies\TrashedChaptersPolicy;
use App\Services\Retention\RetentionPolicy;
use App\Services\Retention\RetentionRegistry;
use App\Services\Visio\Recording\SeanceRecordingRetentionService;
use Illuminate\Support\ServiceProvider;

/**
 * Les domaines purgeables (#690).
 *
 * ## Pourquoi un provider à part
 *
 * La liaison tenait en une méthode ; `AppServiceProvider` l'aurait absorbée sans
 * broncher. La garde de taille a refusé — 317 lignes pour un plafond de 300, et
 * `register()` à 41 lignes pour un plafond de 40 — et elle a eu raison : ce
 * fichier est l'endroit où tout finit par se greffer faute d'abstraction.
 *
 * La rétention est une préoccupation bornée, avec son propre registre et son
 * propre cycle d'ajout. Elle a sa place ici.
 *
 * ## Ajouter un domaine purgeable
 *
 * Écrire une classe qui implémente {@see RetentionPolicy},
 * l'ajouter à la liste ci-dessous. Ni le moteur ni la commande `purge:run` ne
 * changent — c'est l'article 1 du contrat OCP appliqué à la destruction de
 * données (#697).
 *
 * `bind()` et non `singleton()` — article 3. Un singleton capturerait le premier
 * tenant résolu, et ce dépôt a déjà payé ce bug (`ResolveInstitution::handle():55`).
 * Ces politiques sont sans état, mais la règle ne souffre pas d'exception :
 * c'est ainsi qu'elle tient.
 */
final class RetentionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RetentionRegistry::class, fn ($app): RetentionRegistry => new RetentionRegistry([
            new SoftDeletedUsersPolicy,
            new SoftDeletedInstitutionsPolicy,
            new AuditLogsPolicy,
            // Ces deux-là enveloppent un service métier existant : la logique de
            // destruction reste chez lui, la politique ne fait que la présenter
            // au moteur. Résolus par le conteneur, donc leurs propres
            // dépendances (stockage des médias, artefacts) le sont aussi.
            new SeanceRecordingsPolicy($app->make(SeanceRecordingRetentionService::class)),
            new TrashedChaptersPolicy($app->make(ChapterRetentionService::class)),
        ]));
    }
}
