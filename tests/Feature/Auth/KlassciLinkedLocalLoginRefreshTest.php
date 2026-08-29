<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\Auth\KlassciAuthClient;
use App\Services\Klassci\Auth\KlassciTenantDiscovery;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Régression : un compte KLASSCI avec un mot de passe local valide ne doit pas
 * conserver silencieusement un ancien token utilisateur au prochain login.
 */
final class KlassciLinkedLocalLoginRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_local_user_refreshes_klassci_token_before_login_success(): void
    {
        $institution = Institution::factory()->create([
            'slug' => 'presentation',
            'klassci_api_url' => 'https://presentation.klassci.test/api/lms',
            'is_active' => true,
        ]);
        $user = User::factory()->for($institution)->teacher()->create([
            'email' => 'teacher@klassci.test',
            'password' => Hash::make('correct-password'),
            'klassci_id' => 200001,
            'klassci_token' => 'expired-token',
        ]);

        $payload = [
            'data' => [
                'user' => [
                    'id' => 200001,
                    'email' => $user->email,
                    'nom' => $user->name,
                    'role' => 'enseignant',
                ],
                'token' => 'fresh-token',
            ],
            'meta' => [],
        ];

        $this->mock(KlassciAuthClient::class, function ($mock) use ($payload): void {
            $mock->shouldReceive('attemptLogin')
                ->once()
                ->with('https://presentation.klassci.test/api/lms', 'teacher@klassci.test', 'correct-password')
                ->andReturn($payload);
        });
        $this->mock(KlassciUserSynchronizer::class, function ($mock) use ($user): void {
            $mock->shouldReceive('sync')
                ->once()
                ->withArgs(fn (array $remote, string $token): bool => $remote['id'] === 200001 && $token === 'fresh-token')
                ->andReturn($user);
        });
        $this->mock(KlassciTenantDiscovery::class, function ($mock): void {
            $mock->shouldNotReceive('findMatchingTenants');
        });

        $response = $this->postJson('/api/auth/login', [
            'username' => 'teacher@klassci.test',
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.klassci_synced', true)
            ->assertJsonPath('meta.institution', 'presentation');
    }

    public function test_unavailable_refresh_preserves_existing_local_fallback(): void
    {
        $institution = Institution::factory()->create([
            'klassci_api_url' => 'https://school.klassci.test/api/lms',
            'is_active' => true,
        ]);
        User::factory()->for($institution)->teacher()->create([
            'email' => 'offline@klassci.test',
            'password' => Hash::make('correct-password'),
            'klassci_id' => 42,
        ]);

        $this->mock(KlassciAuthClient::class, function ($mock): void {
            $mock->shouldReceive('attemptLogin')->once()->andReturnNull();
        });
        $this->mock(KlassciTenantDiscovery::class, function ($mock): void {
            $mock->shouldNotReceive('findMatchingTenants');
        });

        $response = $this->postJson('/api/auth/login', [
            'username' => 'offline@klassci.test',
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.klassci_synced', false);
    }
}
