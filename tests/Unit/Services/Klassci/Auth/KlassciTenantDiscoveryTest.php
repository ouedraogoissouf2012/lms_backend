<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Klassci\Auth;

use App\Models\Institution;
use App\Services\Klassci\Auth\KlassciTenantDiscovery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Issue #120 — Tests unit pour KlassciTenantDiscovery.
 *
 * Couvre :
 * - Happy path : 1+ tenants matchent
 * - Edge case : aucun tenant actif
 * - **Garde anti-régression bug** : tenant retourne ConnectionException (le bug
 *   pré-fix faisait crash 500 — voir spec §1.2)
 * - Tenant retourne 5xx
 * - Tenant retourne 200 mais found=false
 * - Pas de fuite password dans les logs
 *
 * @see app/Services/Klassci/Auth/KlassciTenantDiscovery.php
 * @see .claude/specs/auth-controller-refactor/requirements.md REQ-1
 */
#[CoversClass(KlassciTenantDiscovery::class)]
final class KlassciTenantDiscoveryTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.klassci.ssl_verify', true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_empty_array_when_no_active_tenants(): void
    {
        // Aucune institution active créée
        Institution::query()->update(['is_active' => false]);

        $logger = Mockery::spy(LoggerInterface::class);
        $discovery = new KlassciTenantDiscovery(Http::getFacadeRoot(), $logger);

        $result = $discovery->findMatchingTenants('any@user.com');

        self::assertSame([], $result);
    }

    public function test_finds_single_matching_tenant(): void
    {
        Institution::factory()->create([
            'slug'            => 'alpha',
            'klassci_api_url' => 'https://alpha.klassci.test',
            'is_active'       => true,
        ]);
        Institution::factory()->create([
            'slug'            => 'beta',
            'klassci_api_url' => 'https://beta.klassci.test',
            'is_active'       => true,
        ]);

        Http::fake([
            'alpha.klassci.test/auth/check-user' => Http::response(['data' => ['found' => true]], 200),
            'beta.klassci.test/auth/check-user'  => Http::response(['data' => ['found' => false]], 200),
        ]);

        $logger = Mockery::spy(LoggerInterface::class);
        $discovery = new KlassciTenantDiscovery(Http::getFacadeRoot(), $logger);

        $result = $discovery->findMatchingTenants('user@alpha.com');

        self::assertCount(1, $result);
        self::assertSame('alpha', $result[0]['code']);
    }

    public function test_finds_multiple_matching_tenants(): void
    {
        Institution::factory()->create([
            'slug'            => 'alpha',
            'klassci_api_url' => 'https://alpha.klassci.test',
            'is_active'       => true,
        ]);
        Institution::factory()->create([
            'slug'            => 'beta',
            'klassci_api_url' => 'https://beta.klassci.test',
            'is_active'       => true,
        ]);

        Http::fake([
            'alpha.klassci.test/auth/check-user' => Http::response(['data' => ['found' => true]], 200),
            'beta.klassci.test/auth/check-user'  => Http::response(['data' => ['found' => true]], 200),
        ]);

        $logger = Mockery::spy(LoggerInterface::class);
        $discovery = new KlassciTenantDiscovery(Http::getFacadeRoot(), $logger);

        $result = $discovery->findMatchingTenants('shared@user.com');

        self::assertCount(2, $result);
    }

    /**
     * 🔑 GARDE ANTI-RÉGRESSION du bug pré-fix `successful()` sur ConnectionException.
     *
     * Avant fix : crash 500 « Call to undefined method ConnectionException::successful() ».
     * Après fix : le tenant down est skip silencieusement, le login continue.
     *
     * Mock direct de HttpFactory pour pouvoir injecter ConnectionException dans
     * le retour de pool() — ce que Http::fake() ne permet pas correctement.
     */
    public function test_skips_tenant_with_connection_exception_without_crashing(): void
    {
        Institution::factory()->create([
            'slug'            => 'down',
            'klassci_api_url' => 'https://down.klassci.test',
            'is_active'       => true,
        ]);
        Institution::factory()->create([
            'slug'            => 'up',
            'klassci_api_url' => 'https://up.klassci.test',
            'is_active'       => true,
        ]);

        // Simule directement le retour de pool() : array indexé par tenant code,
        // avec ConnectionException pour 'down' et Response pour 'up'.
        $upResponse = Mockery::mock(\Illuminate\Http\Client\Response::class);
        $upResponse->shouldReceive('successful')->andReturn(true);
        $upResponse->shouldReceive('json')->andReturn(['data' => ['found' => true]]);

        $http = Mockery::mock(\Illuminate\Http\Client\Factory::class);
        $http->shouldReceive('pool')->andReturn([
            'down' => new ConnectionException('Connection refused'),
            'up'   => $upResponse,
        ]);

        $logger = Mockery::spy(LoggerInterface::class);
        $discovery = new KlassciTenantDiscovery($http, $logger);

        // Ne doit PAS crasher
        $result = $discovery->findMatchingTenants('user@up.com');

        self::assertCount(1, $result);
        self::assertSame('up', $result[0]['code']);

        // Logger doit avoir capturé le tenant down
        $logger->shouldHaveReceived('warning')
            ->with('KLASSCI tenant unreachable during discovery', Mockery::on(function ($context) {
                return ($context['tenant'] ?? null) === 'down';
            }))->once();
    }

    public function test_skips_tenant_with_5xx_response(): void
    {
        Institution::factory()->create([
            'slug'            => 'broken',
            'klassci_api_url' => 'https://broken.klassci.test',
            'is_active'       => true,
        ]);

        Http::fake([
            'broken.klassci.test/auth/check-user' => Http::response(['error' => 'internal'], 500),
        ]);

        $logger = Mockery::spy(LoggerInterface::class);
        $discovery = new KlassciTenantDiscovery(Http::getFacadeRoot(), $logger);

        $result = $discovery->findMatchingTenants('any@user.com');

        self::assertSame([], $result);
    }

    public function test_skips_tenant_when_found_false(): void
    {
        Institution::factory()->create([
            'slug'            => 'doesnotknow',
            'klassci_api_url' => 'https://doesnotknow.klassci.test',
            'is_active'       => true,
        ]);

        Http::fake([
            'doesnotknow.klassci.test/auth/check-user' => Http::response(['data' => ['found' => false]], 200),
        ]);

        $logger = Mockery::spy(LoggerInterface::class);
        $discovery = new KlassciTenantDiscovery(Http::getFacadeRoot(), $logger);

        $result = $discovery->findMatchingTenants('unknown@user.com');

        self::assertSame([], $result);
    }

    public function test_does_not_log_password_in_warnings(): void
    {
        Institution::factory()->create([
            'slug'            => 'sensitive',
            'klassci_api_url' => 'https://sensitive.klassci.test',
            'is_active'       => true,
        ]);

        // Mock direct HttpFactory pour injecter ConnectionException (cf. test ci-dessus).
        $http = Mockery::mock(\Illuminate\Http\Client\Factory::class);
        $http->shouldReceive('pool')->andReturn([
            'sensitive' => new ConnectionException('refused'),
        ]);

        $loggedMessages = [];
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning', 'info', 'error')
            ->andReturnUsing(function ($message, $context) use (&$loggedMessages): void {
                $loggedMessages[] = [$message, $context];
            });

        $discovery = new KlassciTenantDiscovery($http, $logger);

        // Depuis #243 : tous les tenants injoignables → KlassciUnavailableException.
        // On capture l'exception ; le but du test reste inchangé : vérifier le
        // non-log du password, y compris dans le message d'indisponibilité.
        try {
            $discovery->findMatchingTenants('user@sensitive.com');
        } catch (\App\Exceptions\KlassciUnavailableException) {
            // attendu
        }

        // Aucune trace de "password" attendue (rien dans `findMatchingTenants` ne devrait pouvoir leak un password — on garde le test au cas où)
        $allLogged = json_encode($loggedMessages);
        self::assertStringNotContainsString('password', strtolower($allLogged ?: ''));
    }

    public function test_handles_ssl_disabled_correctly(): void
    {
        Config::set('services.klassci.ssl_verify', false);

        Institution::factory()->create([
            'slug'            => 'unsigned',
            'klassci_api_url' => 'https://unsigned.klassci.test',
            'is_active'       => true,
        ]);

        Http::fake([
            'unsigned.klassci.test/auth/check-user' => Http::response(['data' => ['found' => true]], 200),
        ]);

        $logger = Mockery::spy(LoggerInterface::class);
        $discovery = new KlassciTenantDiscovery(Http::getFacadeRoot(), $logger);

        // Ne doit pas crash (le service utilise withoutVerifying())
        $result = $discovery->findMatchingTenants('user@unsigned.com');

        self::assertCount(1, $result);
    }
}
