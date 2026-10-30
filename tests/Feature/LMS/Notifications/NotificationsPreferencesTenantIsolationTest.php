<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Notifications;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
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
    use ActsAsTenantUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    private function userIn(?int $institutionId, string $role): User
    {
        return User::factory()->create([
            'institution_id' => $institutionId,
            'role' => $role,
            'last_klassci_sync' => now(),
        ]);
    }

    public function test_coordinateur_cannot_read_preferences_of_user_in_another_institution(): void
    {
        $instA = Institution::factory()->create(['slug' => 'iso-a']);
        $instB = Institution::factory()->create(['slug' => 'iso-b']);
        $coordA = $this->userIn($instA->id, 'coordinateur');
        $targetB = $this->userIn($instB->id, 'etudiant');
        $this->asTenant($coordA);

        $this->getJson("/api/lms/notifications/preferences/{$targetB->id}")
            ->assertStatus(403);
    }

    public function test_admin_cannot_read_preferences_of_user_in_another_institution(): void
    {
        $instA = Institution::factory()->create(['slug' => 'iso-a2']);
        $instB = Institution::factory()->create(['slug' => 'iso-b2']);
        $adminA = $this->userIn($instA->id, 'admin');
        $targetB = $this->userIn($instB->id, 'etudiant');
        $this->asTenant($adminA);

        $this->getJson("/api/lms/notifications/preferences/{$targetB->id}")
            ->assertStatus(403);
    }

    public function test_manager_can_still_read_same_tenant_user(): void
    {
        $inst = Institution::factory()->create(['slug' => 'iso-same']);
        $coord = $this->userIn($inst->id, 'coordinateur');
        $target = $this->userIn($inst->id, 'etudiant');
        $this->asTenant($coord);

        $this->getJson("/api/lms/notifications/preferences/{$target->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.user_id', $target->id);
    }

    /** @return iterable<string, array{0: bool}> */
    public static function volets(): iterable
    {
        yield 'école reliée à KLASSCI' => [false];
        yield 'école autonome' => [true];
    }

    /**
     * D54 ouvre la route au supradmin plateforme, sans école ; la lecture des
     * préférences d'un autre compte découle de la règle de #512, déjà portée par
     * le contrôleur.
     */
    #[DataProvider('volets')]
    public function test_le_supradmin_sans_ecole_lit_les_preferences_des_deux_volets(bool $autonome): void
    {
        $ecole = $autonome ? Institution::factory()->standalone()->create() : Institution::factory()->create();
        $supradmin = $this->userIn(null, 'supradmin');
        $cible = $this->userIn($ecole->id, 'etudiant');

        $this->asTenant($supradmin)->getJson("/api/lms/notifications/preferences/{$cible->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user_id', $cible->id);
    }
}
