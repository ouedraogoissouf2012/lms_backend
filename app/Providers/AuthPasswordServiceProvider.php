<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\TenantAwareTokenRepository;
use App\Services\Auth\KlassciMotDePasseOublie;
use App\Services\Auth\LocalMotDePasseOublie;
use App\Services\Auth\MotDePasseOublie;
use App\Services\Roster\RosterAuthorityFactory;
use App\Services\TenantManager;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

/**
 * Dépôt de jetons de reset scopé par tenant (#714), et la réponse à « mot de passe oublié »
 * selon l'école (#808).
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

        $this->app->bind(TenantAwareTokenRepository::class, function ($app) {
            $config = $app->make('config')->get('auth.passwords.users', []);
            $table = is_array($config) && is_string($config['table'] ?? null)
                ? $config['table']
                : 'password_reset_tokens';
            $expire = is_array($config) && is_numeric($config['expire'] ?? null)
                ? (int) $config['expire'] * 60
                : 3600;
            $throttle = is_array($config) && is_numeric($config['throttle'] ?? null)
                ? (int) $config['throttle']
                : 0;
            $connection = is_array($config) && is_string($config['connection'] ?? null)
                ? $config['connection']
                : null;
            $keyRaw = $app->make('config')->get('app.key');
            $key = is_string($keyRaw) ? $keyRaw : '';
            if (str_starts_with($key, 'base64:')) {
                $key = base64_decode(substr($key, 7));
            }

            return new TenantAwareTokenRepository(
                $app->make(DatabaseManager::class)->connection($connection),
                $app->make(Hasher::class),
                $table,
                $key,
                $expire,
                $throttle,
                $app->make(TenantManager::class),
            );
        });
    }
}
