<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Enseignants;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Integration tests for `GET /api/lms/enseignants` after extraction to
 * `LMSEnseignantsController` (PR B of LMSDataController split).
 *
 * The new controller proxies to KLASSCI via `KlassciProxyService::getEnseignantsEnrichis()`
 * and stores per-enseignant cache (10 min TTL).
 *
 * @see app/Http/Controllers/API/LMS/LMSEnseignantsController.php
 * @see .claude/specs/lms-data-controller-split/
 */
final class EnseignantsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('PostgreSQL PDO driver not available (CI-only test).');
        }

        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
    }

    private function userInInstitution(string $role = 'enseignant'): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => $role,
        ]);
    }

    public function test_returns_401_when_unauthenticated(): void
    {
        $response = $this->getJson('/api/lms/enseignants');

        $response->assertStatus(401);
    }

    public function test_returns_enseignants_simple_format(): void
    {
        $user = $this->userInInstitution();
        Sanctum::actingAs($user);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getEnseignantsEnrichis')
                ->once()
                ->with(false)
                ->andReturn([
                    'success' => true,
                    'data' => [
                        ['id' => 1, 'nom' => 'Doe', 'email' => 'doe@example.com'],
                        ['id' => 2, 'nom' => 'Smith', 'email' => 'smith@example.com'],
                    ],
                    'meta' => ['count' => 2],
                ]);
        });

        $response = $this->getJson('/api/lms/enseignants');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.source', 'klassci_externe');
    }

    public function test_returns_500_when_klassci_fails(): void
    {
        $user = $this->userInInstitution();
        Sanctum::actingAs($user);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getEnseignantsEnrichis')
                ->once()
                ->andReturn([
                    'success' => false,
                    'message' => 'KLASSCI down',
                ]);
        });

        $response = $this->getJson('/api/lms/enseignants');

        $response->assertStatus(500)
            ->assertJsonPath('success', false);
    }

    public function test_with_details_triggers_enriched_format(): void
    {
        $user = $this->userInInstitution();
        Sanctum::actingAs($user);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getEnseignantsEnrichis')
                ->once()
                ->with(true)
                ->andReturn([
                    'success' => true,
                    'data' => [
                        ['id' => 1, 'nom' => 'Doe', 'matieres' => [], 'classes' => []],
                    ],
                    'meta' => [],
                ]);
        });

        $response = $this->getJson('/api/lms/enseignants?with_details=1');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.lms_cache_enabled', true);
    }
}
