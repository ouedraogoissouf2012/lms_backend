<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Services\Klassci\Auth\KlassciTenantDiscovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * #242 — Une exception inattendue pendant le login doit être TRACÉE côté
 * serveur (et pas seulement transformée en 500 générique muet).
 *
 * Avant ce fix, `catch (\Exception)` avalait l'exception sans la logger, rendant
 * les incidents (ex. table audit absente, 2026-06-20) impossibles à diagnostiquer.
 *
 * @see app/Http/Controllers/API/AuthController.php::login
 */
final class LoginErrorLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unexpected_exception_during_login_is_logged(): void
    {
        // La discovery KLASSCI lève une exception inattendue (simule un plantage
        // post-détection : sync, audit, etc.).
        $this->mock(KlassciTenantDiscovery::class, function ($mock): void {
            $mock->shouldReceive('findMatchingTenants')
                ->andThrow(new \RuntimeException('boom interne'));
        });

        Log::spy();

        // username non-local → passe l'auth locale (null) → atteint la discovery
        // mockée qui throw → catch → log + réponse générique.
        $response = $this->postJson('/api/auth/login', [
            'username' => 'compte.inexistant.local',
            'password' => 'peu-importe',
        ]);

        // Le client reçoit une erreur générique (pas de détail technique).
        $this->assertSame(500, $response->status());

        // Mais l'incident est tracé côté serveur, avec le contexte utile.
        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context = []): bool {
                return str_contains($message, 'login')
                    && ($context['exception'] ?? null) === \RuntimeException::class;
            })
            ->once();
    }

    public function test_password_is_never_logged(): void
    {
        $this->mock(KlassciTenantDiscovery::class, function ($mock): void {
            $mock->shouldReceive('findMatchingTenants')
                ->andThrow(new \RuntimeException('boom'));
        });

        $secret = 'SuperSecretPassword123!';
        Log::spy();

        $this->postJson('/api/auth/login', [
            'username' => 'compte.x',
            'password' => $secret,
        ]);

        // Le mot de passe ne doit JAMAIS apparaître dans les logs.
        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context = []) use ($secret): bool {
                return ! str_contains(json_encode($context) ?: '', $secret);
            })
            ->once();
    }
}
