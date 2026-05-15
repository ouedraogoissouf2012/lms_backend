<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRole;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * CRITICAL-11 — Hide required_roles from 403 response (issue #40 / [HIGH-?]).
 *
 * The 403 response emitted by EnsureRole when a user has insufficient role
 * MUST NOT expose internal authorization metadata to the client :
 *   - no `required_roles` field
 *   - no `your_role` field
 *
 * Such exposure is OWASP A06 (Information Disclosure) — an attacker probing
 * authorization rules can enumerate role patterns and optimize privilege
 * escalation attempts.
 *
 * Server-side, the same info IS logged (Log::warning) for audit. Clients
 * only learn « access denied » + the human-readable message.
 *
 * This test verifies the contract of the JSON response shape ; the
 * authorization logic itself is covered elsewhere.
 */
class EnsureRole403ResponseShapeTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $studentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'role-test-inst']);
        $this->studentUser = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role'           => 'etudiant',
            'name'           => 'Student',
            'email'          => 'student@role-test.test',
        ]);
    }

    /**
     * Helper: run the middleware with a forged authenticated request.
     */
    private function runMiddleware(User $user, string ...$requiredRoles): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create('/api/dummy', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new EnsureRole();
        return $middleware->handle($request, fn () => response('ok'), ...$requiredRoles);
    }

    /**
     * CORE TEST — 403 body must NOT contain required_roles or your_role.
     */
    public function test_403_body_omits_required_roles_and_your_role(): void
    {
        $response = $this->runMiddleware($this->studentUser, 'admin', 'superAdmin');

        $this->assertSame(403, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);

        $this->assertIsArray($body);

        $this->assertArrayNotHasKey(
            'required_roles',
            $body,
            'required_roles MUST NOT appear in the 403 response (OWASP A06 — info disclosure).'
        );

        $this->assertArrayNotHasKey(
            'your_role',
            $body,
            'your_role MUST NOT appear in the 403 response (OWASP A06 — info disclosure).'
        );
    }

    /**
     * The 403 response must still carry minimal, useful info for the client :
     *   - success: false
     *   - message: human-readable reason
     */
    public function test_403_body_contains_success_false_and_message(): void
    {
        $response = $this->runMiddleware($this->studentUser, 'admin');

        $body = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('success', $body);
        $this->assertFalse($body['success']);

        $this->assertArrayHasKey('message', $body);
        $this->assertNotEmpty($body['message']);
        $this->assertIsString($body['message']);
    }

    /**
     * Authorized user (matching role) must pass through (200).
     * Acts as a sanity check that the middleware does not over-block.
     */
    public function test_authorized_user_passes_through(): void
    {
        $teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role'           => 'enseignant',
            'email'          => 'teacher@role-test.test',
        ]);

        $response = $this->runMiddleware($teacher, 'enseignant', 'coordinateur');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }

    /**
     * Unauthenticated request (no user resolved) returns 401 — also
     * minimal shape, no info leak.
     */
    public function test_unauthenticated_request_returns_401_minimal_body(): void
    {
        $request = Request::create('/api/dummy', 'GET');
        $request->setUserResolver(fn () => null);

        $middleware = new EnsureRole();
        $response = $middleware->handle($request, fn () => response('ok'), 'admin');

        $this->assertSame(401, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertArrayNotHasKey('required_roles', $body);
        $this->assertArrayNotHasKey('your_role', $body);
        $this->assertFalse($body['success']);
    }
}
