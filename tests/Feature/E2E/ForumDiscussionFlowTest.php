<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Models\Institution;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E2E — Flow 4 (#211) : cycle de vie complet d'une discussion de forum,
 * avec le fan-out de notifications entre 3 acteurs.
 *
 * Parcours vérifié de bout en bout :
 *
 *   étudiant A pose une question → étudiant B répond (A notifié)
 *   → A répond à B (B notifié) → A marque la réponse de B comme solution
 *   (topic résolu + B notifié) → compteurs topic à jour
 *   → enseignant clôt le topic → plus personne ne peut poster
 *
 * L'enjeu : la boucle notifications est LE moteur d'engagement du forum —
 * et les compteurs (posts_count) sont recalculés explicitement depuis le
 * retrait des boot hooks (#223).
 *
 * @see docs/IMPROVEMENT_PRIORITIES.md CRITICAL #1 (issue #211)
 * @see app/Services/Forum/ForumPostService.php (refreshTopicCounters, #223)
 * @see app/Services/Forum/ForumTopicService.php
 */
final class ForumDiscussionFlowTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $studentA;
    private User $studentB;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
        $this->studentA = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
        $this->studentB = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
        $this->teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
        ]);
    }

    /**
     * Discussion complète : question → réponse → solution, avec le fan-out
     * de notifications vérifié à chaque échange.
     */
    public function test_complete_forum_discussion_with_notifications(): void
    {
        // ── Étape 1 : l'étudiant A pose sa question ────────────────────────
        Sanctum::actingAs($this->studentA);

        $create = $this->postJson('/api/forum/topics', [
            'title' => 'Comment installer Composer ?',
            'content' => 'Je bloque sur l\'installation de Composer sous Windows, une idée ?',
        ]);
        $create->assertStatus(201);
        $topicId = $create->json('data.id');
        $this->assertNotNull($topicId);

        // ── Étape 2 : l'étudiant B répond → A reçoit une notification ──────
        Sanctum::actingAs($this->studentB);

        $reply = $this->postJson("/api/forum/topics/{$topicId}/posts", [
            'content' => 'Télécharge l\'installeur officiel sur getcomposer.org et suis les étapes.',
        ]);
        $reply->assertStatus(201);
        $replyId = $reply->json('data.id');

        $notifA = Notification::where('user_id', $this->studentA->id)
            ->where('type', Notification::TYPE_FORUM_REPLY)
            ->first();
        $this->assertNotNull($notifA, 'L\'auteur du topic doit être notifié de la réponse');
        $this->assertSame($topicId, $notifA->data['topic_id'] ?? null);

        // A voit la notification dans SON flux + le badge non-lu.
        Sanctum::actingAs($this->studentA);
        $unread = $this->getJson('/api/notifications/unread-count');
        $unread->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $unread->json('data.count') ?? $unread->json('count'));

        // ── Étape 3 : A consulte le topic — compteur posts à jour (#223) ───
        $show = $this->getJson("/api/forum/topics/{$topicId}");
        $show->assertStatus(200);
        $this->assertSame(1, $show->json('data.posts_count'));
        $this->assertFalse((bool) $show->json('data.is_resolved'));

        // ── Étape 4 : A marque la réponse de B comme solution ──────────────
        $solution = $this->postJson("/api/forum/posts/{$replyId}/solution");
        $solution->assertStatus(200);

        // Le topic est résolu, B est notifié que sa réponse est acceptée.
        $showResolved = $this->getJson("/api/forum/topics/{$topicId}");
        $this->assertTrue((bool) $showResolved->json('data.is_resolved'));

        $notifB = Notification::where('user_id', $this->studentB->id)
            ->where('type', Notification::TYPE_FORUM_SOLUTION)
            ->first();
        $this->assertNotNull($notifB, 'L\'auteur de la solution doit être notifié');

        // ── Étape 5 : B marque sa notification comme lue ────────────────────
        Sanctum::actingAs($this->studentB);
        $this->postJson("/api/notifications/{$notifB->id}/mark-as-read")
            ->assertStatus(200);
        $this->assertNotNull($notifB->fresh()->read_at);

        // ── Étape 6 : l'enseignant clôt le topic → plus de nouveaux posts ──
        Sanctum::actingAs($this->teacher);
        $this->postJson("/api/forum/topics/{$topicId}/close")
            ->assertStatus(200);

        Sanctum::actingAs($this->studentB);
        $this->postJson("/api/forum/topics/{$topicId}/posts", [
            'content' => 'Une réponse de plus après clôture, qui doit être refusée.',
        ])->assertStatus(403);
    }

    /**
     * Un étudiant ne peut PAS clore ni épingler un topic (frontière de rôle),
     * et ne peut pas marquer une solution sur le topic d'un autre.
     */
    public function test_forum_role_boundaries_hold_throughout_flow(): void
    {
        Sanctum::actingAs($this->studentA);
        $topicId = $this->postJson('/api/forum/topics', [
            'title' => 'Question de A sur les middlewares',
            'content' => 'Comment fonctionne le pipeline de middlewares dans Laravel ?',
        ])->json('data.id');

        Sanctum::actingAs($this->studentB);
        $replyId = $this->postJson("/api/forum/topics/{$topicId}/posts", [
            'content' => 'Chaque middleware enveloppe le suivant, comme des oignons.',
        ])->json('data.id');

        // B (simple participant) ne peut pas clore/épingler le topic de A.
        $this->postJson("/api/forum/topics/{$topicId}/close")->assertStatus(403);
        $this->postJson("/api/forum/topics/{$topicId}/pin")->assertStatus(403);

        // B ne peut pas auto-marquer SA réponse comme solution (seul l'auteur
        // du topic ou un modérateur le peut).
        $this->postJson("/api/forum/posts/{$replyId}/solution")->assertStatus(403);
    }
}
