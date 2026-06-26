<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Klassci;

use App\Exceptions\KlassciUnavailableException;
use App\Services\Klassci\KlassciConfigResolver;
use App\Services\TenantManager;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Issue #270 — Robustesse : URL de base KLASSCI absente/invalide.
 *
 * Quand `KLASSCI_API_URL` (config `services.klassci.url`) est vide ou absente,
 * l'ancien code construisait une URL sans scheme (`"/etudiants"`) et Guzzle
 * levait `InvalidArgumentException: The scheme '' is not allowed` — rendu en
 * 500 trompeur (« bug serveur LMS ») alors que la cause est une panne/mauvaise
 * config EXTERNE.
 *
 * `requireBaseUrl()` valide l'URL AVANT toute requête et lève
 * {@see KlassciUnavailableException} (→ 503) si elle est inexploitable. Ce test
 * isole la logique de garde, sans I/O ni HTTP réel.
 *
 * @see app/Services/Klassci/KlassciConfigResolver.php
 */
#[CoversClass(KlassciConfigResolver::class)]
final class KlassciConfigResolverUrlGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_require_base_url_returns_a_valid_https_url(): void
    {
        $resolver = $this->resolverForConfiguredUrl('https://klassci.test');

        self::assertSame('https://klassci.test', $resolver->requireBaseUrl());
    }

    public function test_require_base_url_accepts_http_scheme(): void
    {
        $resolver = $this->resolverForConfiguredUrl('http://klassci.local:8080');

        self::assertSame('http://klassci.local:8080', $resolver->requireBaseUrl());
    }

    public function test_require_base_url_throws_when_url_is_empty_string(): void
    {
        $resolver = $this->resolverForConfiguredUrl('');

        $this->expectException(KlassciUnavailableException::class);

        $resolver->requireBaseUrl();
    }

    public function test_require_base_url_throws_when_url_is_null(): void
    {
        // TenantManager renvoie url=null ET la config globale est vidée → aucune
        // source ne fournit d'URL exploitable.
        config()->set('services.klassci.url', null);
        $resolver = $this->resolverForConfiguredUrl(null);

        $this->expectException(KlassciUnavailableException::class);

        $resolver->requireBaseUrl();
    }

    public function test_require_base_url_throws_when_scheme_is_missing(): void
    {
        // Cause historique exacte du 500 : une valeur sans scheme http(s).
        $resolver = $this->resolverForConfiguredUrl('klassci.test/api');

        $this->expectException(KlassciUnavailableException::class);

        $resolver->requireBaseUrl();
    }

    public function test_require_base_url_throws_when_scheme_is_not_http(): void
    {
        $resolver = $this->resolverForConfiguredUrl('ftp://klassci.test');

        $this->expectException(KlassciUnavailableException::class);

        $resolver->requireBaseUrl();
    }

    public function test_require_base_url_throws_when_host_is_missing(): void
    {
        $resolver = $this->resolverForConfiguredUrl('https://');

        $this->expectException(KlassciUnavailableException::class);

        $resolver->requireBaseUrl();
    }

    /**
     * Vrai resolver (classe finale, non mockable) avec collaborateurs stubés.
     * Sans utilisateur authentifié, la résolution tombe en priorité 3 (token
     * système via {@see TenantManager::klassciConfig()}) — c'est cette URL
     * système qu'on contrôle pour le test.
     */
    private function resolverForConfiguredUrl(?string $url): KlassciConfigResolver
    {
        $guard = Mockery::mock(Guard::class);
        $guard->shouldReceive('user')->andReturn(null);

        $auth = Mockery::mock(AuthFactory::class);
        $auth->shouldReceive('guard')->with('sanctum')->andReturn($guard);

        $tenant = Mockery::mock(TenantManager::class);
        $tenant->shouldReceive('klassciConfig')->andReturn([
            'url'   => $url,
            'token' => 'system-token',
        ]);

        return new KlassciConfigResolver($auth, $tenant, new NullLogger());
    }
}
