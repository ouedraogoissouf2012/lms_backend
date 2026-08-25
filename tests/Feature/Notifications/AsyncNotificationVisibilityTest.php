<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Institution;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationDispatcher;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #579 — une notification émise hors contexte tenant (worker de queue, cron)
 * était écrite avec `institution_id = NULL`.
 *
 * `BelongsToInstitution` (hook `creating`, `:100-110`) se contente de
 * journaliser quand aucun tenant n'est résolu. À la LECTURE en revanche, une
 * requête HTTP authentifiée résout bien le tenant et le scope global ajoute
 * `WHERE institution_id = X` : la ligne à NULL est exclue.
 *
 * Résultat : la notification existe en base et son destinataire ne la voit
 * jamais. Les tests existants vérifiaient la création, jamais la visibilité —
 * c'est exactement l'écart par lequel le bug est passé.
 *
 * @see app/Services/Notification/NotificationDispatcher.php
 */
final class AsyncNotificationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->app->make(TenantManager::class)->set($this->institution);

        $this->student = User::factory()->student()->create([
            'institution_id' => $this->institution->id,
        ]);
    }

    public function test_notification_emitted_without_tenant_carries_the_recipient_institution(): void
    {
        $this->dispatchFromWorker();

        $row = Notification::withoutGlobalScope('institution')
            ->where('user_id', $this->student->id)
            ->firstOrFail();

        self::assertSame(
            $this->institution->id,
            $row->institution_id,
            'La notification a été écrite sans institution : son destinataire ne la verra jamais.'
        );
    }

    public function test_notification_emitted_without_tenant_is_visible_to_its_recipient(): void
    {
        $this->dispatchFromWorker();

        // = toute requête HTTP authentifiée : le tenant est résolu, le scope
        // global filtre.
        $this->app->make(TenantManager::class)->set($this->institution);

        self::assertSame(1, Notification::where('user_id', $this->student->id)->count());
    }

    /**
     * Cycle complet : le job réel s'exécute dans un worker sans tenant, puis
     * le destinataire consulte l'API. C'est le scénario utilisateur exact —
     * les tests existants s'arrêtaient à la création en base.
     */
    public function test_visio_job_notification_appears_in_the_recipient_api_listing(): void
    {
        $this->dispatchFromWorker();
        $this->actAsAuthenticatedStudent();

        $response = $this->getJson('/api/notifications');

        $response->assertStatus(200);
        self::assertCount(1, $response->json('data'));
    }

    /**
     * `unread-count` et le bloc `recent` sont des chemins de lecture DISTINCTS
     * de la liste paginée : ils doivent voir la notification eux aussi.
     */
    public function test_unread_count_and_recent_widget_see_async_notifications(): void
    {
        $this->dispatchFromWorker();
        $this->actAsAuthenticatedStudent();

        $this->getJson('/api/notifications/unread-count')
            ->assertStatus(200)
            ->assertJsonPath('count', 1);

        $recent = $this->getJson('/api/notifications/recent');
        $recent->assertStatus(200);
        self::assertCount(1, $recent->json('data'));
    }

    /**
     * Non-régression : un destinataire sans institution (supradmin) garde
     * `NULL`. Le hook `creating` respecte une assignation explicite, même à
     * `null` — le tenant ambiant ne doit pas s'y substituer.
     */
    public function test_supradmin_recipient_keeps_a_null_institution(): void
    {
        $supradmin = User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]);

        $this->app->make(NotificationDispatcher::class)->send(
            $supradmin,
            Notification::TYPE_VISIO_STARTING,
            'Visio',
            'Message.',
        );

        $row = Notification::withoutGlobalScope('institution')
            ->where('user_id', $supradmin->id)
            ->firstOrFail();

        self::assertNull($row->institution_id);
    }

    /**
     * Authentifie par un VRAI bearer token, pas par `Sanctum::actingAs()`.
     *
     * `actingAs()` feint le garde sans émettre de token : `ResolveInstitution`
     * (préfixé au groupe `api`) commence par `reset()` puis ne trouve ni
     * bearer token ni en-tête `X-Institution` — le tenant reste donc NUL et le
     * scope global s'efface. La ligne à `institution_id = NULL` redevient
     * visible et le test passerait au VERT sur le code bogué.
     *
     * En production, le client envoie un token : le tenant EST résolu et la
     * ligne disparaît. Émettre un vrai token est donc la seule façon de
     * reproduire la condition réelle — c'est ce faux négatif du harnais qui a
     * permis à #579 de passer inaperçu.
     */
    private function actAsAuthenticatedStudent(): void
    {
        $token = $this->student->createToken('async-visibility')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}");
    }

    /** Émet une notification dans les conditions d'un worker : aucun tenant résolu. */
    private function dispatchFromWorker(): void
    {
        $this->app->make(TenantManager::class)->reset();

        $this->app->make(NotificationDispatcher::class)->send(
            $this->student,
            Notification::TYPE_VISIO_STARTING,
            'Visio',
            'Votre visioconférence démarre.',
        );
    }
}
