<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Notifications;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #512 — le bypass « manager » de `getNotificationPreferences` ne vérifiait
 * pas le tenant : un coordinateur/admin de l'institution A pouvait lire les
 * préférences d'un utilisateur de l'institution B (IDOR cross-tenant, latent tant
 * que l'endpoint est un stub, mais à fermer AVANT le câblage de
 * `parent_notification_preferences`).
 *
 * Règle : un manager n'accède qu'aux utilisateurs de SON institution ; le
 * cross-tenant est réservé au gestionnaire PLATEFORME (`isPlatformSupradmin`).
 *
 * @see app/Http/Controllers/API/LMS/LMSNotificationsPreferencesController.php
 */
final class NotificationsPreferencesTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function userIn(?int $institutionId, string $role): User
    {
        return User::factory()->create([
            'institution_id' => $institutionId,
            'role' => $role,
        ]);
    }

    public function test_coordinateur_cannot_read_preferences_of_user_in_another_institution(): void
    {
        $instA = Institution::factory()->create(['slug' => 'iso-a']);
        $instB = Institution::factory()->create(['slug' => 'iso-b']);
        $coordA = $this->userIn($instA->id, 'coordinateur');
        $targetB = $this->userIn($instB->id, 'etudiant');
        Sanctum::actingAs($coordA);

        $this->getJson("/api/lms/notifications/preferences/{$targetB->id}")
            ->assertStatus(403);
    }

    public function test_admin_cannot_read_preferences_of_user_in_another_institution(): void
    {
        $instA = Institution::factory()->create(['slug' => 'iso-a2']);
        $instB = Institution::factory()->create(['slug' => 'iso-b2']);
        $adminA = $this->userIn($instA->id, 'admin');
        $targetB = $this->userIn($instB->id, 'etudiant');
        Sanctum::actingAs($adminA);

        $this->getJson("/api/lms/notifications/preferences/{$targetB->id}")
            ->assertStatus(403);
    }

    public function test_manager_can_still_read_same_tenant_user(): void
    {
        $inst = Institution::factory()->create(['slug' => 'iso-same']);
        $coord = $this->userIn($inst->id, 'coordinateur');
        $target = $this->userIn($inst->id, 'etudiant');
        Sanctum::actingAs($coord);

        $this->getJson("/api/lms/notifications/preferences/{$target->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.user_id', $target->id);
    }

    public function test_platform_supradmin_can_read_cross_tenant(): void
    {
        $instB = Institution::factory()->create(['slug' => 'iso-plat']);
        $supradmin = $this->userIn(null, 'supradmin');
        $targetB = $this->userIn($instB->id, 'etudiant');
        Sanctum::actingAs($supradmin);

        $this->getJson("/api/lms/notifications/preferences/{$targetB->id}")
            ->assertStatus(200);
    }
}
