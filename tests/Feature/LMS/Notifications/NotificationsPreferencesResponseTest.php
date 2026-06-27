<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Notifications;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de
 * `LMSNotificationsPreferencesController` (axe #1, groupe G1 — test-first AVANT
 * migration vers `RespondsWithJson`).
 *
 * Verrouille la forme JSON des enveloppes migrables :
 *   - 403 `getNotificationPreferences` → `assertExactJson` ;
 *   - succès des deux endpoints → présence des clés `success`/`message`/`data`.
 *
 * Cas NON migré : les deux handlers `catch` 500 émettent une clé custom `error`
 * (singulier) que le contrat du trait (`errors` pluriel) ne peut pas reproduire.
 * Ils restent intacts (ils ne sont pas déclenchables sur ces stubs sans mock).
 *
 * @see app/Http/Controllers/API/LMS/LMSNotificationsPreferencesController.php
 */
final class NotificationsPreferencesResponseTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
    }

    private function userInInstitution(string $role = 'etudiant'): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => $role,
        ]);
    }

    // ───────────────────────── getNotificationPreferences ─────────────────────────

    public function test_get_preferences_for_self_returns_success_with_data(): void
    {
        $user = $this->userInInstitution();
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/lms/notifications/preferences/{$user->id}");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame($user->id, $body['data']['user_id']);
    }

    public function test_get_preferences_for_other_user_returns_403_error_envelope(): void
    {
        $user = $this->userInInstitution('etudiant');
        $other = $this->userInInstitution('etudiant');
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/lms/notifications/preferences/{$other->id}");

        $response->assertStatus(403)
            ->assertExactJson(['success' => false, 'message' => 'Accès refusé']);
    }

    // ───────────────────────── sendSessionReminder ─────────────────────────

    public function test_send_session_reminder_returns_success_message_and_data(): void
    {
        $user = $this->userInInstitution('coordinateur');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/lms/notifications/send-session-reminder', [
            'seance_cours_id' => 42,
            'channels' => ['email', 'app'],
            'minutes_before' => 30,
        ]);

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'message', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame('Rappels acceptés (intégration NotificationService en cours).', $body['message']);
        $this->assertSame(0, $body['data']['sent_count']);
    }
}
