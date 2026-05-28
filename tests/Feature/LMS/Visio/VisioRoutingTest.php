<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Smoke tests for `LMSVisioController` after extraction (PR I of LMS split).
 *
 * 8 endpoints migrated. These tests focus on the **HTTP contract**:
 *   - Routes correctly point to the new controller class.
 *   - Auth/role middleware is preserved.
 *   - REQ-4: the visio participants route is now reachable at
 *     `/seances/{id}/visio-participants` (the legacy `/participants` URI was
 *     unreachable due to a collision with `LMSSeancesController::seanceParticipants`).
 *
 * Full integration testing of the KLASSCI proxy + visio state machine is out
 * of scope of this refactor (no new behavior introduced — 1:1 extraction with
 * the two anti-patterns replaced by service calls).
 *
 * @see app/Http/Controllers/API/LMS/LMSVisioController.php
 * @see .claude/specs/lms-data-controller-split/
 */
final class VisioRoutingTest extends TestCase
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

    public function test_activate_visio_requires_auth(): void
    {
        $this->postJson('/api/lms/seances/42/activate-visio')->assertStatus(401);
    }

    public function test_activate_visio_blocked_for_etudiant_by_role_middleware(): void
    {
        Sanctum::actingAs($this->userInInstitution('etudiant'));

        $this->postJson('/api/lms/seances/42/activate-visio')->assertStatus(403);
    }

    public function test_deactivate_visio_requires_auth(): void
    {
        $this->postJson('/api/lms/seances/42/deactivate-visio')->assertStatus(401);
    }

    public function test_deactivate_visio_blocked_for_coordinateur_by_role_middleware(): void
    {
        // deactivateVisio is `role:enseignant` only (coordinateurs not allowed)
        Sanctum::actingAs($this->userInInstitution('coordinateur'));

        $this->postJson('/api/lms/seances/42/deactivate-visio')->assertStatus(403);
    }

    public function test_start_visio_requires_auth(): void
    {
        $this->postJson('/api/lms/seances/42/start-visio')->assertStatus(401);
    }

    public function test_start_visio_blocked_for_etudiant_by_role_middleware(): void
    {
        Sanctum::actingAs($this->userInInstitution('etudiant'));

        $this->postJson('/api/lms/seances/42/start-visio')->assertStatus(403);
    }

    public function test_end_visio_requires_auth(): void
    {
        $this->postJson('/api/lms/seances/42/end-visio')->assertStatus(401);
    }

    public function test_end_visio_blocked_for_etudiant_by_role_middleware(): void
    {
        Sanctum::actingAs($this->userInInstitution('etudiant'));

        $this->postJson('/api/lms/seances/42/end-visio')->assertStatus(403);
    }

    public function test_join_visio_requires_auth(): void
    {
        $this->postJson('/api/lms/seances/42/join')->assertStatus(401);
    }

    public function test_leave_visio_requires_auth(): void
    {
        $this->postJson('/api/lms/seances/42/leave')->assertStatus(401);
    }

    public function test_heartbeat_visio_requires_auth(): void
    {
        $this->postJson('/api/lms/seances/42/heartbeat')->assertStatus(401);
    }

    /**
     * REQ-4 of the spec: visio participants route is reachable at the renamed URI.
     */
    public function test_visio_participants_requires_auth(): void
    {
        $this->getJson('/api/lms/seances/42/visio-participants')->assertStatus(401);
    }

    /**
     * REQ-4 — Sanity check: the renamed visio route resolves to the visio controller,
     * NOT to `LMSSeancesController::seanceParticipants` (which keeps the legacy URI).
     */
    public function test_visio_participants_route_uses_lms_visio_controller(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('lms.seances.visio-participants');

        self::assertNotNull($route, 'Route lms.seances.visio-participants should be registered.');
        self::assertSame(
            \App\Http\Controllers\API\LMS\LMSVisioController::class . '@getVisioParticipants',
            $route->getActionName(),
        );
    }

    /**
     * REQ-4 — Sanity check: the legacy `/seances/{id}/participants` route still
     * points to `LMSSeancesController::seanceParticipants`, so the rename did NOT
     * accidentally break the enrolment-list endpoint.
     */
    public function test_legacy_participants_route_still_uses_lms_seances_controller(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('lms.seances.participants');

        self::assertNotNull($route, 'Route lms.seances.participants should be registered.');
        self::assertSame(
            \App\Http\Controllers\API\LMS\LMSSeancesController::class . '@seanceParticipants',
            $route->getActionName(),
        );
    }
}
