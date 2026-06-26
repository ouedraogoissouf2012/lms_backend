<?php

declare(strict_types=1);

namespace Tests\Feature\Proxy;

use App\Exceptions\KlassciUnavailableException;
use App\Services\KlassciProxyService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #270 — Quand `KLASSCI_API_URL` est vide/absente, les routes `/api/proxy/*`
 * doivent dégrader proprement en 503 (panne/mauvaise config EXTERNE, retryable),
 * et NON 500 (qui suggère à tort un bug serveur LMS). Le body brut KLASSCI ou le
 * détail technique ne doivent JAMAIS fuiter au client.
 *
 * @see app/Services/Klassci/KlassciConfigResolver.php::requireBaseUrl
 * @see app/Http/Controllers/API/Proxy/Concerns/RendersKlassciProxyErrors.php
 */
final class KlassciProxyUrlGuardTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsFreshCoordinator(): void
    {
        // `last_klassci_sync = now()` (défaut factory) → données fraîches : le
        // middleware EnsureKlassciSync ne déclenche aucun appel KLASSCI, on isole
        // donc le comportement du contrôleur proxy lui-même.
        $user = User::factory()->create([
            'role'              => 'coordinateur',
            'last_klassci_sync' => now(),
        ]);

        Sanctum::actingAs($user);
    }

    public function test_empty_klassci_url_returns_503_not_500_end_to_end(): void
    {
        // Aucune institution résolue en test (pas de bearer token réel) → la config
        // tombe en priorité 3 sur la valeur globale, qu'on vide ici.
        config()->set('services.klassci.url', '');
        config()->set('services.klassci.token', '');

        $this->actingAsFreshCoordinator();

        $response = $this->getJson('/api/proxy/structure');

        $response->assertStatus(503)
            ->assertJsonPath('success', false);

        // Jamais le détail technique (« The scheme '' is not allowed », body brut…).
        self::assertStringNotContainsString('scheme', strtolower((string) $response->getContent()));
    }

    public function test_klassci_unavailable_exception_is_presented_as_503(): void
    {
        // Wiring du trait : une KlassciUnavailableException remontée du service est
        // traduite en 503 par le contrôleur (et non avalée en 500 par le catch large).
        $this->mock(KlassciProxyService::class, function ($mock): void {
            $mock->shouldReceive('getStructure')
                ->andThrow(KlassciUnavailableException::missingBaseUrl());
        });

        $this->actingAsFreshCoordinator();

        $response = $this->getJson('/api/proxy/structure');

        $response->assertStatus(503)
            ->assertJsonPath('success', false);
    }

    public function test_generic_failure_still_returns_500_not_503(): void
    {
        // Non-régression : une panne NON liée à l'indisponibilité KLASSCI reste un
        // 500 générique. On ne transforme pas toutes les erreurs en 503.
        $this->mock(KlassciProxyService::class, function ($mock): void {
            $mock->shouldReceive('getStructure')
                ->andThrow(new \RuntimeException('boom interne inattendu'));
        });

        $this->actingAsFreshCoordinator();

        $response = $this->getJson('/api/proxy/structure');

        $response->assertStatus(500)
            ->assertJsonPath('success', false);
    }
}
