<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Services\TenantManager;
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
