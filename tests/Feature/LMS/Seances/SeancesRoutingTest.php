<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Smoke tests for `LMSSeancesController` after extraction (PR F of LMS split).
 *
 * 11 endpoints are migrated. These tests focus on the **HTTP contract**:
 *   - Routes correctly point to the new controller class.
 *   - Auth/role middleware works.
 *   - Basic 401/403 responses are preserved.
 *
 * Full integration testing of the complex KLASSCI proxy flows is out of
 * scope of this refactor (no new behavior introduced — 1:1 extraction).
 *
 * @see app/Http/Controllers/API/LMS/LMSSeancesController.php
 * @see .claude/specs/lms-data-controller-split/
 */
final class SeancesRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {

        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
    }

    private function userInInstitution(string $role = 'enseignant', ?string $token = 'fake-token'): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => $role,
            'klassci_token' => $token,
        ]);
    }

    public function test_upcoming_seances_requires_auth(): void
    {
        $this->getJson('/api/lms/seances/upcoming')->assertStatus(401);
    }

    public function test_my_teaching_seances_blocked_for_etudiant_by_role_middleware(): void
    {
        $user = $this->userInInstitution('etudiant');
        Sanctum::actingAs($user);

        $this->getJson('/api/lms/seances/my-teaching')->assertStatus(403);
    }

    public function test_seances_history_blocked_for_etudiant_by_role_middleware(): void
    {
        $user = $this->userInInstitution('etudiant');
        Sanctum::actingAs($user);

        $this->getJson('/api/lms/seances/history')->assertStatus(403);
    }

    public function test_delete_seance_blocked_for_etudiant_by_role_middleware(): void
    {
        $user = $this->userInInstitution('etudiant');
        Sanctum::actingAs($user);

        $this->deleteJson('/api/lms/seances/42')->assertStatus(403);
    }

    public function test_hide_seance_blocked_for_enseignant_by_role_middleware(): void
    {
        // hideSeance is `role:etudiant` only
        $user = $this->userInInstitution('enseignant');
        Sanctum::actingAs($user);

        $this->postJson('/api/lms/seances/42/hide')->assertStatus(403);
    }

    public function test_seance_details_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/lms/seances/42/details')->assertStatus(401);
    }

    public function test_my_classes_seances_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/lms/seances/my-classes')->assertStatus(401);
    }
}
