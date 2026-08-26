<?php

declare(strict_types=1);

namespace Tests\Feature\Proxy;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #619 — les écritures proxy portent le jeton de CETTE requête, pas celui
 * mémoïsé sur le contrôleur (Octane).
 */
final class ProxyAcademicWriteIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_URL = 'https://klassci.tenant-619.test';

    /** @var list<string> */
    private array $bearers = [];

    public function test_two_teachers_do_not_reuse_the_first_bearer_on_save_notes(): void
    {
        $institution = Institution::factory()->create([
            'klassci_api_url' => self::TENANT_URL,
            'klassci_api_token' => 'token-institution',
            'is_active' => true,
        ]);
        $alice = $this->teacher($institution, 'token-alice');
        $bob = $this->teacher($institution, 'token-bob');
        $this->fakeKlassci();

        $this->postNotes($alice, 1, 12)->assertOk();
        $this->flushRequestBoundary();
        $this->postNotes($bob, 2, 15)->assertOk();

        self::assertSame(['token-alice', 'token-bob'], $this->bearers);
    }

    public function test_write_without_personal_token_is_refused(): void
    {
        $institution = Institution::factory()->create([
            'klassci_api_url' => self::TENANT_URL,
            'is_active' => true,
        ]);
        $teacher = $this->teacher($institution, null);

        $this->postNotes($teacher, 1, 12)->assertStatus(401);
    }

    private function teacher(Institution $institution, ?string $token): User
    {
        return User::factory()->for($institution)->create([
            'role' => 'enseignant',
            'klassci_token' => $token,
            'klassci_tenant_url' => self::TENANT_URL,
            'last_klassci_sync' => now(),
        ]);
    }

    private function postNotes(User $user, int $etudiantId, int $note): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken('619')->plainTextToken,
            'Accept' => 'application/json',
        ])->postJson('/api/proxy/evaluations/9/notes', [
            'notes' => [['etudiant_id' => $etudiantId, 'note' => $note]],
        ]);
    }

    private function fakeKlassci(): void
    {
        $factory = new HttpFactory();
        $factory->fake(function (ClientRequest $request) {
            $bearer = $request->header('Authorization')[0] ?? '';
            $this->bearers[] = str_replace('Bearer ', '', $bearer);

            return Http::response(['success' => true, 'data' => ['saved' => 1]]);
        });
        $this->app->instance(HttpFactory::class, $factory);
    }

    private function flushRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();
        $this->app['auth']->forgetGuards();
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }
    }
}
