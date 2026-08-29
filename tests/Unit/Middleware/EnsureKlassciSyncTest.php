<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\EnsureKlassciSync;
use App\Models\User;
use App\Services\Klassci\Data\KlassciDataWhitelist;
use App\Services\KlassciProxyService;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRITICAL-05 (issue #34) — tests that verify the 24h-resync middleware
 * cannot silently escalate a user's LMS role from a compromised KLASSCI
 * payload.
 *
 * The middleware is allowed to update: `name`, `klassci_role`, `klassci_data`,
 * `last_klassci_sync`. It is FORBIDDEN to update: `role`, `email`.
 *
 * Spec: `.claude/specs/critical-05-klassci-role-separation/`
 */
#[CoversClass(EnsureKlassciSync::class)]
final class EnsureKlassciSyncTest extends EnsureKlassciSyncTestCase
{
    public function test_resync_does_not_overwrite_role(): void
    {
        $user = $this->staleUser('etudiant');

        $this->runMiddlewareWith($user, [
            'role'  => 'supradmin',     // attaque — KLASSCI tente une escalade
            'email' => 'attacker@evil.com',
            'nom'   => 'Renamed',
        ]);

        $user->refresh();
        self::assertSame('etudiant', $user->role, 'LMS role MUST remain unchanged after passive re-sync.');
    }

    public function test_resync_updates_klassci_role(): void
    {
        $user = $this->staleUser('etudiant');

        $this->runMiddlewareWith($user, [
            'role'  => 'supradmin',
            'email' => 'whatever@x.fr',
            'nom'   => 'Renamed',
        ]);

        $user->refresh();
        self::assertSame('supradmin', $user->klassci_role, 'klassci_role must reflect the KLASSCI payload.');
    }

    public function test_resync_does_not_overwrite_email(): void
    {
        $user = $this->staleUser('etudiant', email: 'real@school.fr');

        $this->runMiddlewareWith($user, [
            'role'  => 'etudiant',
            'email' => 'attacker@evil.com',
            'nom'   => 'Renamed',
        ]);

        $user->refresh();
        self::assertSame(
            'real@school.fr',
            $user->email,
            'Email MUST be preserved during passive re-sync to prevent password-reset hijacking.',
        );
    }

    public function test_resync_updates_name_and_klassci_data(): void
    {
        $user = $this->staleUser('etudiant');
        $oldSync = $user->last_klassci_sync;

        $this->runMiddlewareWith($user, [
            'role'  => 'etudiant',
            'email' => 'real@school.fr',
            'nom'   => 'Updated Name',
        ]);

        $user->refresh();
        self::assertSame('Updated Name', $user->name);
        self::assertSame('etudiant', json_decode($user->getRawOriginal('klassci_data'), true)['role']);
        self::assertTrue($user->last_klassci_sync->gt($oldSync), 'last_klassci_sync should advance.');
    }

    public function test_resync_logs_warning_on_role_divergence(): void
    {
        $user = $this->staleUser('etudiant');

        // On capture les warnings via un spy custom plutôt que la chain
        // `shouldHaveReceived->withArgs` (Mockery ne la supporte pas).
        $capturedWarnings = $this->captureLogWarnings();

        $this->runMiddlewareWith($user, [
            'role'  => 'supradmin',
            'email' => 'real@school.fr',
            'nom'   => 'X',
        ]);

        $divergence = $this->findWarningByEvent($capturedWarnings, 'klassci_role_divergence_detected');
        self::assertNotNull($divergence, 'klassci_role_divergence_detected warning should fire');
        $ctx = $divergence[1];
        self::assertSame($user->id, $ctx['user_id']);
        self::assertSame($this->institution->id, $ctx['institution_id']);
        self::assertSame('etudiant', $ctx['lms_role']);
        self::assertSame('supradmin', $ctx['klassci_role_received']);
        self::assertSame('etudiant', $ctx['klassci_role_previous']);
        self::assertTrue($ctx['is_escalation_attempt']);
    }

    public function test_resync_does_not_log_warning_when_roles_match(): void
    {
        $user = $this->staleUser('enseignant');

        $capturedWarnings = $this->captureLogWarnings();

        $this->runMiddlewareWith($user, [
            'role'  => 'enseignant',
            'email' => 'real@school.fr',
            'nom'   => 'X',
        ]);

        $divergence = $this->findWarningByEvent($capturedWarnings, 'klassci_role_divergence_detected');
        self::assertNull($divergence, 'Aucun warning de divergence ne doit fire quand les rôles matchent');
    }

    public function test_klassci_api_failure_does_not_overwrite_role_or_email(): void
    {
        $user = $this->staleUser('etudiant', email: 'real@school.fr');
        $originalKlassciRole = $user->klassci_role;

        $klassciService = Mockery::mock(KlassciProxyService::class);
        $klassciService->shouldReceive('requestWithUserToken')
            ->with($user->klassci_token, 'auth/me', 'GET')
            ->andThrow(new \Exception('KLASSCI down'));

        $request = Request::create('/api/dummy', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new EnsureKlassciSync($klassciService, new KlassciDataWhitelist());
        $middleware->handle($request, fn ($req) => new Response('ok', 200));

        $user->refresh();
        self::assertSame('etudiant', $user->role);
        self::assertSame('real@school.fr', $user->email);
        self::assertSame($originalKlassciRole, $user->klassci_role, 'klassci_role must also be preserved on API failure.');
    }

    public function test_escalation_attempt_flag_is_false_when_klassci_role_is_less_permissive(): void
    {
        // LMS = enseignant (level 2), KLASSCI = etudiant (level 1) — NOT an escalation.
        $user = $this->staleUser('enseignant');

        $capturedWarnings = $this->captureLogWarnings();

        $this->runMiddlewareWith($user, [
            'role'  => 'etudiant',
            'email' => 'real@school.fr',
            'nom'   => 'X',
        ]);

        $divergence = $this->findWarningByEvent($capturedWarnings, 'klassci_role_divergence_detected');
        self::assertNotNull($divergence, 'Warning de divergence attendu même quand non-escalade');
        self::assertFalse($divergence[1]['is_escalation_attempt'], 'is_escalation_attempt doit être false quand KLASSCI < LMS');
    }

    public function test_fresh_user_skips_resync_entirely(): void
    {
        // User synced 1h ago — middleware should NOT call KLASSCI.
        $user = User::factory()->create([
            'institution_id'    => $this->institution->id,
            'role'              => 'etudiant',
            'klassci_role'      => 'etudiant',
            'email'             => 'real@school.fr',
            'name'              => 'Untouched',
            'last_klassci_sync' => now()->subHour(),
        ]);

        $klassciService = Mockery::mock(KlassciProxyService::class);
        $klassciService->shouldNotReceive('requestWithUserToken');

        $request = Request::create('/api/dummy', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new EnsureKlassciSync($klassciService, new KlassciDataWhitelist());
        $response = $middleware->handle($request, fn ($req) => new Response('ok', 200));

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Issue #119 — passive resync must NEVER overwrite `klassci_enseignant_id`.
     * The column is initialized write-once at KLASSCI sign-up via
     * `AuthController::syncUserFromKlassci` CREATE branch.
     */
    public function test_resync_does_not_overwrite_klassci_enseignant_id(): void
    {
        $user = User::factory()->create([
            'institution_id'        => $this->institution->id,
            'role'                  => 'enseignant',
            'klassci_role'          => 'enseignant',
            'klassci_enseignant_id' => 42,
            'email'                 => 'real@school.fr',
            'name'                  => 'Original Name',
            'klassci_data'          => json_encode(['enseignant_id' => 42]),
            'last_klassci_sync'     => now()->subHours(25),
        ]);

        // Compromised KLASSCI returns a different enseignant_id (impersonation).
        $this->runMiddlewareWith($user, [
            'role'          => 'enseignant',
            'email'         => 'real@school.fr',
            'nom'           => 'X',
            'enseignant_id' => 999,
        ]);

        $user->refresh();
        self::assertSame(42, $user->klassci_enseignant_id, 'klassci_enseignant_id MUST remain 42 after passive re-sync.');
    }
}
