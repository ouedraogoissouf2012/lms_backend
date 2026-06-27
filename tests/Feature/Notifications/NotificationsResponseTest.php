<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Institution;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de `NotificationsController`
 * (axe #1, groupe G1 — test-first AVANT migration vers `RespondsWithJson`).
 *
 * Verrouille la forme JSON exacte des 9 réponses de succès afin qu'une migration
 * vers `successResponse` ne change RIEN côté client :
 *   - enveloppes `{success, message}` figées en `assertExactJson` ;
 *   - enveloppes `{success, data}` / `{success, data, meta}` figées via présence/
 *     absence de clés (le `data` n'est pas déterministe).
 *
 * Cas NON migré (figé ici pour le prouver) : `unreadCount` renvoie une clé custom
 * `count` à la racine, que le contrat du trait (`data`/`meta`) ne peut pas
 * reproduire — la migration le laisse donc intact.
 *
 * @see app/Http/Controllers/API/NotificationsController.php
 */
final class NotificationsResponseTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->user = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
    }

    private function makeNotification(bool $read = false): Notification
    {
        return Notification::factory()->create([
            'user_id' => $this->user->id,
            'institution_id' => $this->institution->id,
            'read_at' => $read ? now() : null,
        ]);
    }

    // ───────────────────────── index ─────────────────────────

    public function test_index_returns_success_with_data_and_meta(): void
    {
        $this->makeNotification();
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/notifications');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'data', 'meta'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame(
            ['current_page', 'last_page', 'per_page', 'total'],
            array_keys($body['meta']),
        );
    }

    // ───────────────────────── unreadCount (NON migré) ─────────────────────────

    public function test_unread_count_returns_success_with_root_count_key(): void
    {
        $this->makeNotification();
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/notifications/unread-count');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'count'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame(1, $body['count']);
    }

    // ───────────────────────── recent ─────────────────────────

    public function test_recent_returns_success_with_data_without_message(): void
    {
        $this->makeNotification();
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/notifications/recent');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('message', $body);
    }

    // ───────────────────────── markAsRead ─────────────────────────

    public function test_mark_as_read_returns_success_message_envelope(): void
    {
        $notification = $this->makeNotification();
        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/notifications/{$notification->id}/mark-as-read");

        $response->assertStatus(200)
            ->assertExactJson(['success' => true, 'message' => 'Notification marquée comme lue']);
    }

    // ───────────────────────── markAllAsRead ─────────────────────────

    public function test_mark_all_as_read_returns_success_message_envelope(): void
    {
        $this->makeNotification();
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/notifications/mark-all-as-read');

        $response->assertStatus(200)
            ->assertExactJson([
                'success' => true,
                'message' => 'Toutes les notifications ont été marquées comme lues',
            ]);
    }

    // ───────────────────────── delete ─────────────────────────

    public function test_delete_returns_success_message_envelope(): void
    {
        $notification = $this->makeNotification();
        Sanctum::actingAs($this->user);

        $response = $this->deleteJson("/api/notifications/{$notification->id}");

        $response->assertStatus(200)
            ->assertExactJson(['success' => true, 'message' => 'Notification supprimée']);
    }

    // ───────────────────────── deleteAllRead ─────────────────────────

    public function test_delete_all_read_returns_success_message_envelope(): void
    {
        $this->makeNotification(read: true);
        Sanctum::actingAs($this->user);

        $response = $this->deleteJson('/api/notifications/read/all');

        $response->assertStatus(200)
            ->assertExactJson([
                'success' => true,
                'message' => 'Toutes les notifications lues ont été supprimées',
            ]);
    }

    // ───────────────────────── create (admin) ─────────────────────────

    public function test_create_returns_success_message_envelope(): void
    {
        $coordinator = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'coordinateur',
        ]);
        Sanctum::actingAs($coordinator);

        $response = $this->postJson('/api/admin/notifications/create', [
            'user_id' => $this->user->id,
            'title' => 'Titre',
            'message' => 'Corps du message',
        ]);

        $response->assertStatus(200)
            ->assertExactJson(['success' => true, 'message' => 'Notification envoyée']);
    }

    // ───────────────────────── stats (admin) ─────────────────────────

    public function test_stats_returns_success_with_data_without_message(): void
    {
        $coordinator = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'coordinateur',
        ]);
        Sanctum::actingAs($coordinator);

        $response = $this->getJson('/api/admin/notifications/stats');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('message', $body);
    }
}
