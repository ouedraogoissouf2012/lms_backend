<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * config('session.secure') est figé au boot du framework de test (un seul
 * APP_ENV par process PHPUnit) : impossible de le ré-évaluer via config().
 * Ces tests exécutent donc le fichier réel de config/session.php à froid,
 * avec des variables d'environnement contrôlées, plutôt que de réimplémenter
 * sa logique.
 *
 * #[RunInSeparateProcess] est obligatoire ici, pas optionnel : muter
 * $_ENV/$_SERVER/putenv() dans le process PHPUnit partagé a fait fuiter
 * APP_ENV=production vers les tests suivants en CI (cascade de centaines
 * d'erreurs « no such table: users » — la connexion DB résolue par d'autres
 * tests dépendait de l'APP_ENV réel du process, pas d'un état par-test isolé).
 * Chaque méthode tourne donc dans son propre process enfant : impossible de
 * contaminer le process PHPUnit principal quel que soit l'ordre d'exécution.
 */
final class SessionSecureCookieDefaultTest extends TestCase
{
    private const CONFIG_PATH = __DIR__ . '/../../../config/session.php';

    #[RunInSeparateProcess]
    public function test_production_without_explicit_override_defaults_to_secure_true(): void
    {
        $this->setEnv('APP_ENV', 'production');
        $this->unsetEnv('SESSION_SECURE_COOKIE');

        $config = require self::CONFIG_PATH;

        $this->assertTrue($config['secure']);
    }

    #[RunInSeparateProcess]
    public function test_local_without_explicit_override_keeps_secure_false(): void
    {
        $this->setEnv('APP_ENV', 'local');
        $this->unsetEnv('SESSION_SECURE_COOKIE');

        $config = require self::CONFIG_PATH;

        $this->assertFalse($config['secure']);
    }

    #[RunInSeparateProcess]
    public function test_explicit_override_is_respected_even_in_production(): void
    {
        $this->setEnv('APP_ENV', 'production');
        $this->setEnv('SESSION_SECURE_COOKIE', 'false');

        $config = require self::CONFIG_PATH;

        $this->assertFalse($config['secure']);
    }

    private function setEnv(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function unsetEnv(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}
