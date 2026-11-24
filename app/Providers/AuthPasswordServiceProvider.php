<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Auth\KlassciMotDePasseOublie;
use App\Services\Auth\LocalMotDePasseOublie;
use App\Services\Auth\MotDePasseOublie;
use App\Services\Roster\RosterAuthorityFactory;
use App\Services\User\DefinitionDuMotDePasse;
use App\Services\User\KlassciDefinitionDuMotDePasse;
use App\Services\User\LocalDefinitionDuMotDePasse;
use Illuminate\Support\ServiceProvider;

/**
 * La réponse à « mot de passe oublié » selon l'école (#808), et la définition d'un mot de passe
 * par l'administration (#1275).
 *
 * #1306 : le dépôt de jetons du broker Laravel (#714) est retiré avec le broker ; le lien de
 * réinitialisation est le jeton d'activation (#808).
 */
final class AuthPasswordServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // #808 : `bind()`, résolu à chaque demande, jamais `singleton()` (il rejouerait la
        // première école servie). Le contrôleur la reçoit par sa MÉTHODE. Le seul endroit qui
        // compare le mode reste `RosterAuthorityFactory`.
        $this->app->bind(
            MotDePasseOublie::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)
                ->sourceForCurrentTenant(KlassciMotDePasseOublie::class, LocalMotDePasseOublie::class),
        );

        // #1275 : même règle ; en école KLASSCI, le mot de passe se change dans KLASSCI.
        $this->app->bind(
            DefinitionDuMotDePasse::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)
                ->sourceForCurrentTenant(KlassciDefinitionDuMotDePasse::class, LocalDefinitionDuMotDePasse::class),
        );
    }
}
