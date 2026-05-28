<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * CRITICAL-05 (issue #34) — end-to-end and multi-tenant security tests.
 *
 * Verifies that a compromised KLASSCI server cannot escalate a user's LMS
 * authorization role via the passive 24h re-sync, even across institutions.
 *
 * Spec: `.claude/specs/critical-05-klassci-role-separation/`
 *
 * @see \App\Http\Middleware\EnsureKlassciSync
 * @see \App\Services\Klassci\Auth\KlassciUserSynchronizer::sync
 */
final class KlassciRoleSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {

        parent::setUp();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Swap the global `KlassciProxyService` binding so that any call to
     * `get('auth/me')` returns the provided payload.
     */
    private function mockKlassciAuthMe(array $klassciUser): void
    {
        $mock = Mockery::mock(KlassciProxyService::class);
        $mock->shouldReceive('get')
            ->with('auth/me')
            ->andReturn(['data' => ['user' => $klassciUser]]);
        $mock->shouldReceive('get')->byDefault()->andReturn([]);

        $this->app->instance(KlassciProxyService::class, $mock);
    }

    /**
     * Invoke `KlassciUserSynchronizer::sync()` directly. Bypasses the
     * multi-tenant login discovery machinery so we can exercise REQ-3 in isolation.
     */
    private function callSyncUserFromKlassci(array $klassciUser, ?Institution $institution): User
    {
        return $this->app->make(KlassciUserSynchronizer::class)->sync(
            $klassciUser,
            'fake-klassci-token',
            'https://klassci.fake/api',
            $institution,
        );
    }

    /**
     * REQ-3 — On CREATE, both `role` and `klassci_role` are initialized from KLASSCI.
     */
    public function test_initial_sync_initializes_both_roles(): void
    {
        $institution = Institution::factory()->create(['slug' => 'school-a']);

        $user = $this->callSyncUserFromKlassci([
            'id'    => 42,
            'nom'   => 'Doe',
            'email' => 'doe@school-a.fr',
            'role'  => 'etudiant',
        ], $institution);

        self::assertSame('etudiant', $user->role, 'Initial role must be seeded from KLASSCI.');
        self::assertSame('etudiant', $user->klassci_role, 'klassci_role must mirror the seed.');
        self::assertSame('doe@school-a.fr', $user->email);
    }

    /**
     * REQ-3 — On UPDATE (user already exists), LMS `role` is preserved while
     * `klassci_role` reflects whatever KLASSCI reports. This blocks login-time
     * escalation if a user's KLASSCI account was elevated by an attacker.
     */
    public function test_login_does_not_overwrite_role_when_user_exists(): void
    {
        $institution = Institution::factory()->create(['slug' => 'school-a']);

        $existing = User::factory()->create([
            'klassci_id'     => 42,
            'institution_id' => $institution->id,
            'role'           => 'enseignant',
            'klassci_role'   => 'enseignant',
            'email'          => 'real@school-a.fr',
        ]);

        $user = $this->callSyncUserFromKlassci([
            'id'    => 42,
            'nom'   => 'Doe',
            'email' => 'real@school-a.fr',
            'role'  => 'supradmin',    // attacker pushes elevated role
        ], $institution);

        self::assertSame($existing->id, $user->id, 'Same user record.');
        self::assertSame('enseignant', $user->fresh()->role, 'LMS role MUST be preserved on re-login.');
        self::assertSame('supradmin', $user->fresh()->klassci_role, 'klassci_role captures the KLASSCI claim.');
    }

    /**
     * REQ-1 + REQ-4 — Full end-to-end: stale authenticated user hits a
     * `klassci.sync` route, KLASSCI returns an elevated role, the response
     * status reflects the LMS role (no escalation).
     */
    public function test_resync_route_does_not_grant_admin_to_etudiant_when_klassci_lies(): void
    {
        $institution = Institution::factory()->create(['slug' => 'school-a']);

        $user = User::factory()->create([
            'institution_id'    => $institution->id,
            'role'              => 'etudiant',
            'klassci_role'      => 'etudiant',
            'email'             => 'real@school-a.fr',
            'last_klassci_sync' => now()->subHours(25),
        ]);

        // KLASSCI claims supradmin on the next /auth/me — should be ignored for auth.
        $this->mockKlassciAuthMe([
            'id'    => $user->klassci_id,
            'nom'   => $user->name,
            'email' => 'attacker@evil.com',
            'role'  => 'supradmin',
        ]);

        Sanctum::actingAs($user);

        // `/api/lms/seances/upcoming` is protected by `klassci.sync` and is
        // accessible to all authenticated roles — perfect proxy for asserting
        // the request lifecycle. We do NOT care about its body, only that the
        // middleware mutates the user correctly.
        $this->getJson('/api/lms/seances/upcoming');

        $user->refresh();

        self::assertSame('etudiant', $user->role, 'LMS role must remain `etudiant`.');
        self::assertSame('supradmin', $user->klassci_role, 'klassci_role captures the lie for audit.');
        self::assertSame('real@school-a.fr', $user->email, 'Email must be preserved against hijack.');
        self::assertFalse($user->isAdmin(), 'isAdmin() must return false post-resync.');
    }

    /**
     * REQ-7 — Multi-tenant isolation: re-sync of user A must not touch user B
     * sitting in another institution, even when both share the same KLASSCI id.
     */
    public function test_multi_tenant_isolation_on_resync(): void
    {
        $instA = Institution::factory()->create(['slug' => 'school-a']);
        $instB = Institution::factory()->create(['slug' => 'school-b']);

        $userA = User::factory()->create([
            'institution_id'    => $instA->id,
            'role'              => 'etudiant',
            'klassci_role'      => 'etudiant',
            'email'             => 'a@school-a.fr',
            'last_klassci_sync' => now()->subHours(25),
        ]);
        $userB = User::factory()->create([
            'institution_id'    => $instB->id,
            'role'              => 'enseignant',
            'klassci_role'      => 'enseignant',
            'email'             => 'b@school-b.fr',
            'last_klassci_sync' => now()->subHours(25),
        ]);

        $this->mockKlassciAuthMe([
            'id'    => $userA->klassci_id,
            'role'  => 'supradmin',
            'email' => 'evil@evil.com',
            'nom'   => 'A',
        ]);

        Sanctum::actingAs($userA);
        $this->getJson('/api/lms/seances/upcoming');

        $userA->refresh();
        $userB->refresh();

        // User A re-synced — klassci_role updated, role intact.
        self::assertSame('etudiant', $userA->role);
        self::assertSame('supradmin', $userA->klassci_role);

        // User B is completely untouched.
        self::assertSame('enseignant', $userB->role);
        self::assertSame('enseignant', $userB->klassci_role);
        self::assertSame('b@school-b.fr', $userB->email);
    }
}
