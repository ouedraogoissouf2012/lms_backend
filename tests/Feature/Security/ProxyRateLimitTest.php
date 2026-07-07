<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests du rate limiting structuré sur les routes proxy KLASSCI (#214).
 *
 * Vérifie : la limite par utilisateur est appliquée (429 au-delà), les en-têtes
 * `X-RateLimit-*` sont présents, le supradmin est exempté, et la limite est
 * isolée par utilisateur (le quota de l'un n'affecte pas l'autre).
 *
 * Les appels HTTP sortants vers KLASSCI sont mockés (Http::fake) — on teste le
 * middleware de limitation, pas l'intégration KLASSCI.
 *
 * @see app/Providers/RateLimitServiceProvider.php
 * @see routes/api.php (middleware throttle:proxy)
 */
final class ProxyRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);

        // Neutralise tout appel HTTP sortant (KLASSCI) — réponse vide OK.
        Http::fake(['*' => Http::response(['data' => []], 200)]);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => $role,
            // Données KLASSCI fraîches pour passer le middleware klassci.sync.
            'last_klassci_sync' => now(),
            'klassci_token_encrypted' => 'fake-token',
            'klassci_tenant_url' => 'https://school-a.klassci.test',
        ]);
    }

    public function test_rate_limit_headers_are_present_on_proxy_routes(): void
    {
        Sanctum::actingAs($this->user('coordinateur'));

        $response = $this->getJson('/api/proxy/structure');

        // Quel que soit le statut applicatif, le middleware throttle expose les
        // en-têtes de quota.
        $this->assertNotNull(
            $response->headers->get('X-RateLimit-Limit'),
            'L\'en-tête X-RateLimit-Limit doit être présent sur les routes proxy'
        );
        $this->assertSame('100', $response->headers->get('X-RateLimit-Limit'));
        $this->assertNotNull($response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_exceeding_the_limit_returns_429(): void
    {
        Sanctum::actingAs($this->user('coordinateur'));

        // 100 requêtes autorisées, la 101e doit être bloquée.
        for ($i = 0; $i < 100; $i++) {
            $this->getJson('/api/proxy/structure');
        }

        $blocked = $this->getJson('/api/proxy/structure');
        $blocked->assertStatus(429);
        $this->assertNotNull(
            $blocked->headers->get('Retry-After'),
            'Une réponse 429 doit indiquer Retry-After'
        );
        $this->assertGreaterThan(0, (int) $blocked->headers->get('Retry-After'));
    }

    public function test_supradmin_is_exempt_from_rate_limit(): void
    {
        Sanctum::actingAs($this->user('supradmin'));

        // Bien au-delà de la limite standard : aucun 429 pour le supradmin.
        for ($i = 0; $i < 120; $i++) {
            $response = $this->getJson('/api/proxy/structure');
            $this->assertNotSame(429, $response->status());
        }
    }

    public function test_limit_is_isolated_per_user(): void
    {
        // L'utilisateur A épuise son quota.
        $userA = $this->user('coordinateur');
        Sanctum::actingAs($userA);
        for ($i = 0; $i < 100; $i++) {
            $this->getJson('/api/proxy/structure');
        }
        $this->getJson('/api/proxy/structure')->assertStatus(429);

        // L'utilisateur B n'est PAS affecté par le quota de A.
        $userB = $this->user('coordinateur');
        Sanctum::actingAs($userB);
        $this->getJson('/api/proxy/structure')->assertStatus(200);
    }

    public function test_write_routes_use_stricter_limit(): void
    {
        Sanctum::actingAs($this->user('enseignant'));

        $response = $this->postJson('/api/proxy/evaluations/1/notes', ['notes' => []]);

        // Le limiter proxy-write annonce 30/min (plus strict que la lecture).
        $this->assertSame('30', $response->headers->get('X-RateLimit-Limit'));
    }
}
