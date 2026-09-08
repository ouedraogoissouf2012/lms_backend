<?php

declare(strict_types=1);

namespace Tests\Feature\Klassci;

use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\Health\InMemoryKlassciReachability;
use App\Services\Klassci\Health\KlassciReachability;
use App\Services\Klassci\Sync\KlassciSyncGate;
use App\Services\Klassci\Sync\NeverSyncGate;
use App\Services\Klassci\Sync\TokenPresentSyncGate;
use App\Services\Institution\InstitutionConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #712 — user local sans jeton : pas de 403, zéro HTTP.
 */
final class KlassciSyncGateTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    public function test_local_user_without_klassci_token_does_not_call_http(): void
    {
        Http::fake();
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create([
            'role' => 'etudiant',
            'klassci_token' => null,
            'last_klassci_sync' => null,
        ]);

        $this->asTenant($user)
            ->getJson('/api/lessons/my-courses')
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_gate_binding_resolves_two_implementations_in_same_process(): void
    {
        $this->app->bind(KlassciSyncGate::class, TokenPresentSyncGate::class);
        $first = app(KlassciSyncGate::class);
        $this->app->bind(KlassciSyncGate::class, NeverSyncGate::class);
        $second = app(KlassciSyncGate::class);

        self::assertInstanceOf(TokenPresentSyncGate::class, $first);
        self::assertInstanceOf(NeverSyncGate::class, $second);
        self::assertNotSame($first::class, $second::class);
    }

    public function test_connection_tester_uses_reachability_contract(): void
    {
        Http::fake();
        $institution = Institution::factory()->create([
            'klassci_api_url' => 'https://klassci.example/api',
        ]);
        $fake = new InMemoryKlassciReachability;
        $fake->reachable('https://klassci.example/api', 15, 404);
        $this->app->instance(KlassciReachability::class, $fake);

        $result = app(InstitutionConnectionTester::class)->test($institution->id);

        self::assertSame(200, $result['status']);
        self::assertTrue($result['payload']['success']);
        Http::assertNothingSent();
    }
}
