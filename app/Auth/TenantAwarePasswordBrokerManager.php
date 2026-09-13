<?php

declare(strict_types=1);

namespace App\Auth;

use App\Services\TenantManager;
use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;

/**
 * Point de liaison (#714) : le broker users utilise le dépôt tenant-aware.
 */
final class TenantAwarePasswordBrokerManager extends PasswordBrokerManager
{
    /**
     * @param  array<string, mixed>  $config
     */
    protected function createTokenRepository(array $config): TenantAwareTokenRepository
    {
        $keyRaw = $this->app->make('config')->get('app.key');
        $key = is_string($keyRaw) ? $keyRaw : '';
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        $table = is_string($config['table'] ?? null) ? $config['table'] : 'password_reset_tokens';
        $expire = is_numeric($config['expire'] ?? null) ? (int) $config['expire'] * 60 : 3600;
        $throttle = is_numeric($config['throttle'] ?? null) ? (int) $config['throttle'] : 0;
        $connection = is_string($config['connection'] ?? null) ? $config['connection'] : null;

        return new TenantAwareTokenRepository(
            $this->app->make(DatabaseManager::class)->connection($connection),
            $this->app->make(Hasher::class),
            $table,
            $key,
            $expire,
            $throttle,
            $this->app->make(TenantManager::class),
        );
    }
}
