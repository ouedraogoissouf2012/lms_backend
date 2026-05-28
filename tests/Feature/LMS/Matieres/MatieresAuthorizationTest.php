<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Matieres;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Integration tests for `LMSMatieresController` after extraction from
 * `LMSDataController` (PR C of the LMS split spec).
 *
 * Covers the 3 endpoints:
 *   - GET /api/lms/matieres/{matiereId}    (matiereDetails)
 *   - GET /api/admin/matieres              (adminMatieresList)
 *   - GET /api/lms/teacher/my-matieres     (myMatieres)
 *
 * Validates that the HTTP contract is preserved verbatim and that the role-
 * based access (admin/coordinateur for adminMatieresList) is enforced.
 *
 * @see app/Http/Controllers/API/LMS/LMSMatieresController.php
 * @see .claude/specs/lms-data-controller-split/
 */
final class MatieresAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {

        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
    }

    private function userWithKlassciToken(string $role = 'enseignant', ?string $token = 'fake-token'): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => $role,
            'klassci_token' => $token,
        ]);
    }

    // ---------- matiereDetails ----------

    public function test_matiere_details_returns_401_when_no_klassci_token(): void
    {
        $user = $this->userWithKlassciToken(token: null);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/lms/matieres/42');

        $response->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_matiere_details_returns_404_when_klassci_returns_null(): void
    {
        $user = $this->userWithKlassciToken();
        Sanctum::actingAs($user);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with('fake-token', 'matieres/42', 'GET')
                ->andReturn(['data' => null]);
        });

        $response = $this->getJson('/api/lms/matieres/42');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_matiere_details_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/lms/matieres/42');

        $response->assertStatus(401);
    }

    // ---------- adminMatieresList ----------

    public function test_admin_matieres_list_returns_401_when_no_klassci_token(): void
    {
        $user = $this->userWithKlassciToken(role: 'admin', token: null);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/admin/matieres');

        $response->assertStatus(401);
    }

    public function test_admin_matieres_list_returns_empty_data_when_no_matieres(): void
    {
        $user = $this->userWithKlassciToken(role: 'admin');
        Sanctum::actingAs($user);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with('fake-token', 'matieres', 'GET')
                ->andReturn(['data' => []]);
        });

        $response = $this->getJson('/api/admin/matieres');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.statistiques.total', 0);
    }

    public function test_admin_matieres_list_blocked_for_enseignant_by_role_middleware(): void
    {
        // Route is gated by `role:admin,coordinateur` middleware
        $user = $this->userWithKlassciToken(role: 'enseignant');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/admin/matieres');

        $response->assertStatus(403);
    }

    // ---------- myMatieres ----------

    public function test_my_matieres_returns_401_when_no_klassci_token(): void
    {
        // The route middleware enforces `role:enseignant,coordinateur`
        $user = $this->userWithKlassciToken(role: 'enseignant', token: null);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/lms/teacher/my-matieres');

        $response->assertStatus(401);
    }

    public function test_my_matieres_blocked_for_etudiant_by_role_middleware(): void
    {
        $user = $this->userWithKlassciToken(role: 'etudiant');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/lms/teacher/my-matieres');

        $response->assertStatus(403);
    }

    public function test_my_matieres_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/lms/teacher/my-matieres');

        $response->assertStatus(401);
    }
}
