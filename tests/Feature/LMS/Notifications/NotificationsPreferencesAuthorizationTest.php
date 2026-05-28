<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Notifications;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Integration tests for `LMSNotificationsPreferencesController` after extraction
 * from `LMSDataController` (PR D of the LMS split spec).
 *
 * Endpoints:
 *   - GET  /api/lms/notifications/preferences/{userId}
 *   - POST /api/lms/notifications/send-session-reminder
 *
 * Note: both legacy methods are stubs (TODO markers) — these tests cover the
 * HTTP contract (auth + ownership/role rules), not the business logic which
 * is not yet implemented.
 *
 * @see app/Http/Controllers/API/LMS/LMSNotificationsPreferencesController.php
 * @see .claude/specs/lms-data-controller-split/
 */
final class NotificationsPreferencesAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {

        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
    }

    private function userInInstitution(string $role = 'etudiant'): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => $role,
        ]);
    }

    // ---------- getNotificationPreferences ----------

    public function test_user_can_read_their_own_preferences(): void
    {
        $user = $this->userInInstitution();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/lms/notifications/preferences/{$user->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user_id', $user->id);
    }

    public function test_user_cannot_read_other_user_preferences(): void
    {
        $user = $this->userInInstitution('etudiant');
        $other = $this->userInInstitution('etudiant');
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/lms/notifications/preferences/{$other->id}");

        $response->assertStatus(403);
    }

    public function test_coordinateur_can_read_any_user_preferences(): void
    {
        $coord = $this->userInInstitution('coordinateur');
        $target = $this->userInInstitution('etudiant');
        Sanctum::actingAs($coord);

        $response = $this->getJson("/api/lms/notifications/preferences/{$target->id}");

        $response->assertStatus(200);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $user = $this->userInInstitution();

        $response = $this->getJson("/api/lms/notifications/preferences/{$user->id}");

        $response->assertStatus(401);
    }

    // ---------- sendSessionReminder ----------

    public function test_send_session_reminder_requires_auth(): void
    {
        $response = $this->postJson('/api/lms/notifications/send-session-reminder', []);

        $response->assertStatus(401);
    }
}
