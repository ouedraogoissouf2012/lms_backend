<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Exceptions\KlassciUnavailableException;
use App\Services\Klassci\Auth\KlassciTenantDiscovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #243 — Quand KLASSCI est totalement injoignable (tous les tenants en
 * ConnectionException), le login renvoie 503 (panne externe temporaire), et
 * NON 500 (erreur serveur trompeuse) ni 401 (identifiants — alors qu'on n'a
 * pas pu vérifier).
 *
 * @see app/Services/Klassci/Auth/KlassciTenantDiscovery.php
 * @see app/Http/Controllers/API/AuthController.php::login
 */
final class KlassciUnavailableTest extends TestCase
{
    use RefreshDatabase;

    public function test_klassci_unreachable_returns_503_not_500(): void
    {
        // La discovery signale KLASSCI injoignable (tous tenants down).
        $this->mock(KlassciTenantDiscovery::class, function ($mock): void {
            $mock->shouldReceive('findMatchingTenants')
                ->andThrow(new KlassciUnavailableException('Aucun tenant joignable.'));
        });

        $response = $this->postJson('/api/auth/login', [
            'username' => 'compte.klassci',
            'password' => 'peu-importe',
        ]);

        $response->assertStatus(503)
            ->assertJsonPath('success', false);
    }

    public function test_unknown_user_still_returns_401_not_503(): void
    {
        // Tenants joignables mais aucun match → identifiants inconnus → 401.
        $this->mock(KlassciTenantDiscovery::class, function ($mock): void {
            $mock->shouldReceive('findMatchingTenants')->andReturn([]);
        });

        $response = $this->postJson('/api/auth/login', [
            'username' => 'inconnu.total',
            'password' => 'motdepasse-valide',
        ]);

        $response->assertStatus(401);
    }
}
