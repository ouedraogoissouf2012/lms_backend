<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Services\Cache\Purge\TenantCachePurgerFactory;
use App\Services\Cache\Purge\TenantCachePurgerInterface;
use App\Services\Cache\TenantScopedCache;
use App\Services\Cache\TenantScopedCacheInterface;
use App\Services\Klassci\KlassciRequestMemo;
use App\Services\TenantManager;
use App\Support\Shell\ShellExecutor;
use App\Support\Shell\ShellExecutorInterface;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantManager::class);
        $this->app->scoped(KlassciRequestMemo::class);

        // ShellExecutor — sole entry point for external process execution
        // (issue #79 Phase A). Singleton because it is stateless and we want
        // any optional logger (debug) to be the same instance across callers.
        // Bound to the interface so consumers depend on the abstraction
        // (§1.6 D — Dependency Inversion) and tests can swap a mock.
        $this->app->singleton(ShellExecutor::class);
        $this->app->bind(ShellExecutorInterface::class, ShellExecutor::class);

        // TenantScopedCache (#374, spec redis-runtime). Le conteneur ne sait
        // pas résoudre la classe concrète Illuminate\Cache\Repository par
        // réflexion (son constructeur attend un Store non bindé) : on la
        // fait pointer sur la même instance que le contrat — le store par
        // défaut selon config('cache.default') — pour que TenantScopedCache
        // puisse utiliser tags()/supportsTags(), absents du contrat.
        $this->app->bind(
            Repository::class,
            fn ($app) => $app->make(\Illuminate\Contracts\Cache\Repository::class)
        );

        // #547 — la stratégie de purge tenant est choisie par capacité du store
        // ACTIF (tags/database/no-op), à partir du même Repository concret que
        // TenantScopedCache consomme. Résolu paresseusement : la config cache
        // peut différer entre requête HTTP et worker de queue.
        $this->app->bind(
            TenantCachePurgerInterface::class,
            function ($app) {
                $table = config('cache.stores.database.table', 'cache');
                $factory = new TenantCachePurgerFactory(
                    $app->make(\Psr\Log\LoggerInterface::class),
                    is_string($table) ? $table : 'cache',
                );

                return $factory->make($app->make(Repository::class));
            }
        );
        $this->app->bind(
            TenantScopedCacheInterface::class,
            TenantScopedCache::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fix pour MySQL < 5.7.7 et MariaDB < 10.2.2
        // Limite la longueur par défaut des chaînes indexées
        Schema::defaultStringLength(191);

        // Sanctum : utiliser notre modèle PersonalAccessToken
        // qui bypass le Global Scope institution sur la relation tokenable.
        // Sans ça, le supradmin (institution_id = NULL) ne serait pas trouvé
        // quand le header X-Institution résout une institution spécifique.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // L'observer d'invariant #481 de Lesson est désormais auto-enregistré par
        // le trait Publishable (convention `bootXxx`, cf. Auditable) — plus de
        // Model::observe() dispersé ici. Voir app/Models/Concerns/Publishable.php.

        // Morph map des modèles attachables à un File (issue #10 IDOR).
        // Alias court ↔ classe pour la cohérence du stockage `fileable_type`.
        //
        // Note : morphMap() est utilisé en mode NON-strict (pas enforceMorphMap)
        // pour rester compatible avec d'éventuelles rows historiques stockant
        // le FQCN (`App\Models\Lesson` etc.). La sécurité contre l'IDOR vient
        // de la whitelist dans UploadFileRequest et ListFilesRequest — qui
        // n'acceptent QUE les clés courtes listées dans config/fileables.php.
        $morphMap = [];
        $configuredMorphMap = config('fileables.morph_map', []);

        if (is_array($configuredMorphMap)) {
            foreach ($configuredMorphMap as $alias => $class) {
                if (
                    is_string($alias)
                    && is_string($class)
                    && is_subclass_of($class, Model::class)
                ) {
                    $morphMap[$alias] = $class;
                }
            }
        }

        Relation::morphMap($morphMap);
    }
}
