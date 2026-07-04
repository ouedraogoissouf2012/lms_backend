<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Services\Cache\TenantScopedCache;
use App\Services\Cache\TenantScopedCacheInterface;
use App\Services\TenantManager;
use App\Support\Shell\ShellExecutor;
use App\Support\Shell\ShellExecutorInterface;
use Illuminate\Cache\Repository;
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
        $this->app->singleton(TenantManager::class);

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

        // Morph map des modèles attachables à un File (issue #10 IDOR).
        // Alias court ↔ classe pour la cohérence du stockage `fileable_type`.
        //
        // Note : morphMap() est utilisé en mode NON-strict (pas enforceMorphMap)
        // pour rester compatible avec d'éventuelles rows historiques stockant
        // le FQCN (`App\Models\Lesson` etc.). La sécurité contre l'IDOR vient
        // de la whitelist dans UploadFileRequest et ListFilesRequest — qui
        // n'acceptent QUE les clés courtes listées dans config/fileables.php.
        Relation::morphMap(config('fileables.morph_map'));
    }
}
