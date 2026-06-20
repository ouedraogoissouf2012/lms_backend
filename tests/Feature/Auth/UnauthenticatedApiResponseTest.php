<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #244 — Une requête non authentifiée sur une route API protégée doit
 * renvoyer 401 JSON, MÊME sans header `Accept: application/json`.
 *
 * Sans le fix, Laravel tentait une redirection web vers `route('login')`
 * (inexistante en API pure) → 500 `Route [login] not defined` (stack observée
 * le 2026-06-20 via Swagger).
 *
 * @see bootstrap/app.php (handler AuthenticationException)
 */
final class UnauthenticatedApiResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_route_without_accept_json_returns_401_not_500(): void
    {
        // Pas de token + Accept: text/html (comme un navigateur / Swagger mal réglé).
        $response = $this->get('/api/lessons', ['Accept' => 'text/html']);

        $response->assertStatus(401);
    }

    public function test_protected_route_returns_json_body_when_unauthenticated(): void
    {
        $response = $this->getJson('/api/lessons');

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_versioned_route_also_returns_401_without_accept_json(): void
    {
        // L'alias versionné /api/v1 doit avoir le même comportement.
        $response = $this->get('/api/v1/lessons', ['Accept' => 'text/html']);

        $response->assertStatus(401);
    }
}
