<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Models\Chapter;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Models\ForumTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E2E — Flow 5 (#211) : isolation multi-tenant sur un PARCOURS complet.
 *
 * Deux institutions (A et B) avec chacune son enseignant, son étudiant et
 * son contenu (cours publié, quiz, topic forum, notifications). Le test
 * traverse le catalogue, le détail, les écritures et les flux de
 * notifications côté B et vérifie que RIEN de A ne fuit.
 *
 * L'enjeu : c'est l'invariant n°1 de la plateforme KLASSCI — une fuite
 * cross-tenant est l'incident de sécurité maximal (CRITICAL-06, #87, #91,
 * #102).
 *
 * @see docs/IMPROVEMENT_PRIORITIES.md CRITICAL #1 (issue #211)
 * @see app/Models/Traits/BelongsToInstitution.php
 * @see app/Http/Requests/Concerns/ChecksForumAuthorization.php
 */
final class MultiTenantIsolationFlowTest extends TestCase
{
    use RefreshDatabase;

    private Institution $instA;
    private Institution $instB;
    private User $teacherA;
    private User $studentA;
    private User $studentB;
    private Lesson $lessonA;
    private Quiz $quizA;
    private ForumTopic $topicA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instA = Institution::factory()->create(['slug' => 'school-a']);
        $this->instB = Institution::factory()->create(['slug' => 'school-b']);

        $this->teacherA = User::factory()->create([
            'institution_id' => $this->instA->id,
            'role' => 'enseignant',
        ]);
        $this->studentA = User::factory()->create([
            'institution_id' => $this->instA->id,
            'role' => 'etudiant',
        ]);
        $this->studentB = User::factory()->create([
            'institution_id' => $this->instB->id,
            'role' => 'etudiant',
        ]);

        // Contenu de l'institution A — publié et actif.
        $this->lessonA = Lesson::factory()->create([
            'institution_id' => $this->instA->id,
            'enseignant_id' => $this->teacherA->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
        Chapter::factory()->create([
            'lesson_id' => $this->lessonA->id,
            'institution_id' => $this->instA->id,
            'enseignant_id' => $this->teacherA->id,
            'content_type' => 'text',
            'order' => 0,
        ]);
        $this->quizA = Quiz::factory()->create([
            'institution_id' => $this->instA->id,
            'created_by' => $this->teacherA->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
        $this->topicA = ForumTopic::factory()->create([
            'institution_id' => $this->instA->id,
            'user_id' => $this->studentA->id,
        ]);
        Notification::create([
            'user_id' => $this->studentA->id,
            'type' => Notification::TYPE_FORUM_REPLY,
            'title' => 'Notification privée de A',
            'message' => 'Contenu réservé à l\'étudiant A',
            'data' => ['topic_id' => $this->topicA->id],
            'institution_id' => $this->instA->id,
        ]);
    }

    /**
     * L'étudiant B traverse tout le catalogue : AUCUNE ressource de A
     * n'apparaît, aucun détail n'est accessible, aucune écriture ne passe.
     */
    public function test_tenant_b_student_cannot_see_or_touch_tenant_a_resources(): void
    {
        Sanctum::actingAs($this->studentB);

        // ── Lecture catalogue : les listes de B ne contiennent rien de A ───
        $lessons = $this->getJson('/api/lessons');
        $lessons->assertStatus(200);
        $this->assertNotContains(
            $this->lessonA->id,
            array_column($lessons->json('data.data') ?? $lessons->json('data'), 'id'),
            'FUITE CROSS-TENANT : le cours de A est visible dans le catalogue de B'
        );

        $quizzes = $this->getJson('/api/quizzes');
        $quizzes->assertStatus(200);
        $this->assertNotContains(
            $this->quizA->id,
            array_column($quizzes->json('data.data') ?? $quizzes->json('data'), 'id'),
            'FUITE CROSS-TENANT : le quiz de A est visible dans le catalogue de B'
        );

        $topics = $this->getJson('/api/forum/topics');
        $topics->assertStatus(200);
        $this->assertNotContains(
            $this->topicA->id,
            array_column($topics->json('data.data') ?? $topics->json('data'), 'id'),
            'FUITE CROSS-TENANT : le topic de A est visible dans le forum de B'
        );

        // ── Accès direct par ID : 404 (ne pas révéler l'existence) ─────────
        $this->getJson("/api/lessons/{$this->lessonA->id}")
            ->assertStatus(404);

        $quizDetail = $this->getJson("/api/quizzes/{$this->quizA->id}");
        $this->assertContains(
            $quizDetail->status(),
            [403, 404],
            'L\'accès direct au quiz de A doit être refusé'
        );

        // ── Écritures cross-tenant : démarrer le quiz de A, poster sur le
        //    topic de A — tout doit être refusé ────────────────────────────
        $startQuiz = $this->postJson("/api/quizzes/{$this->quizA->id}/start");
        $this->assertContains(
            $startQuiz->status(),
            [403, 404],
            'B ne doit pas pouvoir démarrer le quiz de A'
        );

        $post = $this->postJson("/api/forum/topics/{$this->topicA->id}/posts", [
            'content' => 'Tentative d\'intrusion cross-tenant dans le topic de A.',
        ]);
        $this->assertContains(
            $post->status(),
            [403, 404],
            'B ne doit pas pouvoir poster dans le topic de A'
        );

        // ── Notifications : le flux de B ne contient pas celles de A ───────
        $notifications = $this->getJson('/api/notifications');
        $notifications->assertStatus(200);
        $payload = json_encode($notifications->json());
        $this->assertStringNotContainsString(
            'Notification privée de A',
            (string) $payload,
            'FUITE CROSS-TENANT : une notification de A apparaît dans le flux de B'
        );
    }

    /**
     * Même un ENSEIGNANT de B ne touche pas aux ressources de A : la
     * frontière tenant prime sur le rôle.
     */
    public function test_tenant_b_teacher_cannot_moderate_tenant_a_resources(): void
    {
        $teacherB = User::factory()->create([
            'institution_id' => $this->instB->id,
            'role' => 'enseignant',
        ]);
        Sanctum::actingAs($teacherB);

        // Modération forum cross-tenant : refusée.
        $this->postJson("/api/forum/topics/{$this->topicA->id}/close")
            ->assertStatus(403);
        $this->postJson("/api/forum/topics/{$this->topicA->id}/pin")
            ->assertStatus(403);

        // Publication du cours de A : refusée.
        $publish = $this->postJson("/api/lessons/{$this->lessonA->id}/publish");
        $this->assertContains($publish->status(), [403, 404]);

        // Lecture des tentatives du quiz de A : refusée.
        $attempts = $this->getJson("/api/quizzes/{$this->quizA->id}/attempts");
        $this->assertContains($attempts->status(), [403, 404]);
    }

    /**
     * Les données d'un étudiant restent privées à l'intérieur d'un même
     * tenant : l'étudiant A2 ne lit pas la copie de A1.
     */
    public function test_attempt_privacy_holds_within_same_tenant(): void
    {
        $studentA2 = User::factory()->create([
            'institution_id' => $this->instA->id,
            'role' => 'etudiant',
        ]);

        $attempt = QuizAttempt::factory()->create([
            'quiz_id' => $this->quizA->id,
            'user_id' => $this->studentA->id,
            'institution_id' => $this->instA->id,
            'status' => 'graded',
        ]);

        Sanctum::actingAs($studentA2);

        $this->getJson("/api/quiz-attempts/{$attempt->id}")
            ->assertStatus(403);
    }
}
