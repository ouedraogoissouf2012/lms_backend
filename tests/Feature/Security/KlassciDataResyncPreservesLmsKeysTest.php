<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Issue #477 — Vecteur A₂ (re-sync passive) : la whitelist doit
 *  (a) préserver les clés LMS internes `_lms_*` (dont `_lms_tenant_url`) qui,
 *      avant #477, étaient perdues à chaque re-sync (`EnsureKlassciSync:105`
 *      écrivait le payload KLASSCI seul) ;
 *  (b) dropper toute clé injectée par un KLASSCI compromis (`is_admin`).
 */
final class KlassciDataResyncPreservesLmsKeysTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_resync_preserves_lms_tenant_url_and_drops_injected_keys(): void
    {
        $institution = Institution::factory()->create(['slug' => 'school-a']);

        // User avec _lms_tenant_url dans son blob (posé au sign-up) et sync périmée.
        $user = User::factory()->for($institution)->create([
            'role' => 'etudiant',
            'klassci_role' => 'etudiant',
            'klassci_token' => 'fake-klassci-token',
            'klassci_data' => ['_lms_tenant_url' => 'https://school-a.klassci.test', 'nom' => 'Ancien'],
            'last_klassci_sync' => now()->subHours(25),
        ]);

        // KLASSCI (potentiellement compromis) renvoie un payload SANS _lms_tenant_url
        // et AVEC une clé injectée is_admin.
        $mock = Mockery::mock(KlassciProxyService::class);
        $mock->shouldReceive('requestWithUserToken')
            ->with('fake-klassci-token', 'auth/me', 'GET')
            ->andReturn(['data' => ['user' => [
                'id' => $user->klassci_id,
                'nom' => 'Nouveau',
                'role' => 'etudiant',
                'is_admin' => true,
                'permissions' => ['*'],
            ]]]);
        $mock->shouldReceive('requestWithUserToken')->byDefault()->andReturn([]);
        $this->app->instance(KlassciProxyService::class, $mock);

        Sanctum::actingAs($user);
        // Route protégée par le middleware klassci.sync → déclenche la re-sync.
        $this->getJson('/api/lms/seances/upcoming');

        $blob = $user->fresh()->klassci_data;

        // (a) _lms_tenant_url préservé à travers la re-sync (corrige le finding).
        self::assertSame('https://school-a.klassci.test', $blob['_lms_tenant_url'] ?? null);
        // (b) clés injectées droppées.
        self::assertArrayNotHasKey('is_admin', $blob);
        self::assertArrayNotHasKey('permissions', $blob);
        // Clé display mise à jour et conservée.
        self::assertSame('Nouveau', $blob['nom'] ?? null);
    }
}
