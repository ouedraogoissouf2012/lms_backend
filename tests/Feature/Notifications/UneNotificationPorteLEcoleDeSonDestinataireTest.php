<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\DispatchVisioNotification;
use App\Models\Institution;
use App\Models\Notification;
use App\Models\Seance;
use App\Models\User;
use App\Services\Notification\NotificationDispatcher;
use App\Services\NotificationService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\NullLogger;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #801 (PR 1, la source) — une notification porte l'école de son destinataire, même écrite
 * sans tenant.
 *
 * ## Le défaut, mesuré en production le 23/11
 *
 * `notifications` : 70 lignes, dont 8 à `institution_id` NULL, toutes `visio_starting`, toutes
 * adressées à des ENSEIGNANTS qui ONT une école, écrites du 2026-09-02 au 2026-11-15.
 *
 * `NotificationDispatcher::send()` créait la notification sans `institution_id` et comptait sur
 * le tenant ambiant. Or `visio_starting` part du job `DispatchVisioNotification`, qui n'en a
 * pas : l'enseignant (`send()`) recevait NULL, les élèves (`sendToMany()`, qui pose l'école du
 * compte) non. Tous les `send()` (forum, note reçue) partagent ce défaut dès qu'ils partent
 * sans tenant.
 *
 * ## Le seul chemin qui écrivait NULL légitimement
 *
 * `POST /api/admin/notifications/create` laissait le supradmin cibler un compte sans école
 * (lui-même). Décision : refusé en 422, motif nommé ; le supradmin ne voit aucun contenu et n'a
 * rien à recevoir par ce canal.
 *
 * Tout se passe SANS tenant résolu, sauf la requête HTTP, sous vrai jeton.
 */
final class UneNotificationPorteLEcoleDeSonDestinataireTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    public function test_l_enseignant_d_une_seance_locale_est_averti_avec_son_ecole(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $enseignant = User::factory()->teacher()->create(['institution_id' => $ecole->id]);
        $seance = Seance::factory()->forInstitution($ecole)->create(['created_by' => $enseignant->id, 'klassci_enseignant_id' => null]);

        $this->demarrer($seance->id, ['seance_locale_id' => $seance->id, 'matiere_nom' => 'Comptabilité']);

        $this->assertNotifieAvecSonEcole($enseignant);
    }

    public function test_l_enseignant_d_une_seance_klassci_est_averti_avec_son_ecole(): void
    {
        $ecole = Institution::factory()->create();
        $enseignant = User::factory()->teacher()->create(['institution_id' => $ecole->id, 'klassci_id' => 9101]);

        $this->demarrer(501, ['klassci_enseignant_id' => 9101, 'institution_id' => $ecole->id, 'matiere_nom' => 'Physique']);

        $this->assertNotifieAvecSonEcole($enseignant);
    }

    /** Le défaut est dans `send()` lui-même : tout appelant sans tenant en héritait. */
    public function test_un_envoi_sans_tenant_porte_l_ecole_du_destinataire(): void
    {
        $eleve = User::factory()->create(['institution_id' => Institution::factory()->create()->id]);

        $notification = $this->app->make(NotificationDispatcher::class)
            ->send($eleve, Notification::TYPE_GRADE_RECEIVED, 'Note', 'Votre copie est notée.');

        self::assertSame($eleve->institution_id, $notification->refresh()->institution_id);
    }

    /**
     * L'école du DESTINATAIRE prime sur le tenant ambiant : un tenant d'une autre école ne doit
     * jamais ranger la notification chez elle, où son destinataire ne la verrait pas.
     */
    public function test_l_ecole_du_destinataire_prime_sur_le_tenant(): void
    {
        $ailleurs = Institution::factory()->create();
        $eleve = User::factory()->create(['institution_id' => Institution::factory()->create()->id]);
        $this->app->make(TenantManager::class)->set($ailleurs);

        $notification = $this->app->make(NotificationDispatcher::class)
            ->send($eleve, Notification::TYPE_GRADE_RECEIVED, 'Note', 'Votre copie est notée.');

        self::assertSame($eleve->institution_id, Notification::query()->withoutGlobalScope('institution')->findOrFail($notification->id)->institution_id);
    }

    public function test_le_supradmin_ne_peut_pas_se_notifier_lui_meme(): void
    {
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        $this->asTenant($supradmin)->postJson('/api/admin/notifications/create', $this->payload($supradmin->id))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'recipient_without_institution');

        self::assertSame(0, Notification::query()->withoutGlobalScope('institution')->count());
    }

    public function test_le_supradmin_notifie_un_compte_d_ecole_avec_l_ecole_du_destinataire(): void
    {
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);
        $destinataire = User::factory()->create(['institution_id' => Institution::factory()->create()->id]);

        $this->asTenant($supradmin)->postJson('/api/admin/notifications/create', $this->payload($destinataire->id))
            ->assertSuccessful();

        $ecrite = Notification::query()->withoutGlobalScope('institution')->where('user_id', $destinataire->id)->sole();
        self::assertSame($destinataire->institution_id, $ecrite->institution_id);
    }

    /** @param  array<string, mixed>  $seanceData */
    private function demarrer(int $seanceId, array $seanceData): void
    {
        (new DispatchVisioNotification(DispatchVisioNotification::STARTING, $seanceId, $seanceData))
            ->handle($this->app->make(NotificationService::class), new NullLogger);
    }

    private function assertNotifieAvecSonEcole(User $destinataire): void
    {
        $notification = Notification::query()->withoutGlobalScope('institution')
            ->where('user_id', $destinataire->id)
            ->where('type', Notification::TYPE_VISIO_STARTING)
            ->sole();

        self::assertSame($destinataire->institution_id, $notification->institution_id, 'Écrite sans tenant : NULL.');
    }

    /** @return array<string, mixed> */
    private function payload(int $destinataireId): array
    {
        return ['user_id' => $destinataireId, 'title' => 'Information', 'message' => 'Un message.', 'type' => 'info'];
    }
}
