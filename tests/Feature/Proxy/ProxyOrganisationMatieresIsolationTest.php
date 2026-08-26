<?php

declare(strict_types=1);

namespace Tests\Feature\Proxy;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * #616 — Isolation par porteur de `/api/proxy/matieres` et `/api/proxy/matieres/{id}`.
 *
 * Même vecteur que #591 : `getMatieres()` / `getMatiereDetails()` passaient par
 * `get()` (clé tenant-globale) alors que KLASSCI attache le jeton personnel.
 *
 * @see tests/Feature/Proxy/ProxyAcademicPerUserIsolationTest.php
 */
final class ProxyOrganisationMatieresIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_URL = 'https://klassci.tenant-616.test';

    /** @var array<string, int> */
    private array $networkCalls = [];

    public function test_two_users_of_same_tenant_do_not_share_matieres(): void
    {
        [$alice, $bob] = $this->twoUsers();
        $this->fakeKlassci();

        $aliceResponse = $this->callAs($alice, '/api/proxy/matieres');
        $aliceResponse->assertOk();
        self::assertSame(11, $aliceResponse->json('data.0.id'));

        $this->flushRequestBoundary();

        $bobResponse = $this->callAs($bob, '/api/proxy/matieres');
        $bobResponse->assertOk();
        self::assertSame(
            22,
            $bobResponse->json('data.0.id'),
            'Bob a reçu les matières d\'Alice — fuite d\'identité via le cache (#616).',
        );
    }

    public function test_two_users_of_same_tenant_do_not_share_matiere_details(): void
    {
        [$alice, $bob] = $this->twoUsers();
        $this->fakeKlassci();

        $this->callAs($alice, '/api/proxy/matieres/7')->assertOk()->assertJsonPath('data.0.id', 11);
        $this->flushRequestBoundary();

        $this->callAs($bob, '/api/proxy/matieres/7')
            ->assertOk()
            ->assertJsonPath('data.0.id', 22);
    }

    public function test_account_without_personal_token_is_refused(): void
    {
        $institution = $this->institution();
        $alice = $this->user($institution, 'token-alice');
        $tokenless = User::factory()->for($institution)->create([
            'role' => 'enseignant',
            'klassci_token' => null,
            'klassci_tenant_url' => self::TENANT_URL,
            'last_klassci_sync' => now(),
        ]);

        $this->fakeKlassci();
        $this->callAs($alice, '/api/proxy/matieres')->assertOk();
        $this->flushRequestBoundary();

        $this->callAs($tokenless, '/api/proxy/matieres')->assertStatus(401);
        self::assertSame(1, $this->networkCalls['matieres'] ?? 0);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function twoUsers(): array
    {
        $institution = $this->institution();

        return [
            $this->user($institution, 'token-alice'),
            $this->user($institution, 'token-bob'),
        ];
    }

    private function institution(): Institution
    {
        return Institution::factory()->create([
            'klassci_api_url' => self::TENANT_URL,
            'klassci_api_token' => 'token-institution',
            'is_active' => true,
        ]);
    }

    private function user(Institution $institution, string $token): User
    {
        return User::factory()->for($institution)->create([
            'role' => 'enseignant',
            'klassci_token' => $token,
            'klassci_tenant_url' => self::TENANT_URL,
            'last_klassci_sync' => now(),
        ]);
    }

    private function callAs(User $user, string $uri): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->getJson($uri);
    }

    private function fakeKlassci(): void
    {
        $payloads = [
            'token-alice' => ['id' => 11, 'nom' => 'Maths Alice'],
            'token-bob' => ['id' => 22, 'nom' => 'SVT Bob'],
        ];

        $factory = new HttpFactory();
        $factory->fake(function (ClientRequest $request) use ($payloads) {
            $resource = basename((string) parse_url($request->url(), PHP_URL_PATH));
            $key = is_numeric($resource) ? 'matieres' : $resource;
            $this->networkCalls[$key] = ($this->networkCalls[$key] ?? 0) + 1;

            $bearer = $request->header('Authorization')[0] ?? '';
            $token = str_replace('Bearer ', '', $bearer);

            return Http::response(['success' => true, 'data' => [$payloads[$token] ?? ['id' => 0]]]);
        });

        $this->app->instance(HttpFactory::class, $factory);
    }

    private function flushRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();

        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }
    }
}
