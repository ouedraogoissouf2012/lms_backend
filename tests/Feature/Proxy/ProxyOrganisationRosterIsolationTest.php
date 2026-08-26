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
 * #617 — le roster `classes/{id}/etudiants` ne doit plus être servi depuis
 * un cache tenant-global : un non-autorisé ne doit pas hériter du hit d'un
 * autorisé.
 */
final class ProxyOrganisationRosterIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_URL = 'https://klassci.tenant-617.test';

    /** @var array<string, int> */
    private array $networkCalls = [];

    public function test_two_users_do_not_share_a_class_roster(): void
    {
        $institution = $this->institution();
        $alice = $this->user($institution, 'token-alice');
        $bob = $this->user($institution, 'token-bob');
        $this->fakeKlassci();

        $this->callAs($alice, '/api/proxy/classes/5/etudiants')
            ->assertOk()
            ->assertJsonPath('data.0.id', 101);

        $this->flushRequestBoundary();

        $this->callAs($bob, '/api/proxy/classes/5/etudiants')
            ->assertOk()
            ->assertJsonPath('data.0.id', 202);
        self::assertSame(2, $this->networkCalls['etudiants'] ?? 0);
    }

    public function test_account_without_personal_token_is_refused(): void
    {
        $institution = $this->institution();
        $alice = $this->user($institution, 'token-alice');
        $tokenless = User::factory()->for($institution)->create([
            'role' => 'etudiant',
            'klassci_token' => null,
            'klassci_tenant_url' => self::TENANT_URL,
            'last_klassci_sync' => now(),
        ]);

        $this->fakeKlassci();
        $this->callAs($alice, '/api/proxy/classes/5/etudiants')->assertOk();
        $this->flushRequestBoundary();

        $this->callAs($tokenless, '/api/proxy/classes/5/etudiants')->assertStatus(401);
        self::assertSame(1, $this->networkCalls['etudiants'] ?? 0);
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
            'token-alice' => ['id' => 101, 'nom' => 'Élève Alice'],
            'token-bob' => ['id' => 202, 'nom' => 'Élève Bob'],
        ];

        $factory = new HttpFactory();
        $factory->fake(function (ClientRequest $request) use ($payloads) {
            $resource = basename((string) parse_url($request->url(), PHP_URL_PATH));
            $this->networkCalls[$resource] = ($this->networkCalls[$resource] ?? 0) + 1;

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
