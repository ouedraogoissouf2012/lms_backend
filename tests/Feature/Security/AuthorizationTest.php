<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests de sécurité — Autorisation : cross-tenant + escalade de privilèges (#212).
 *
 * Deux familles d'attaque :
 *   1. Cross-tenant : un utilisateur du tenant A agit sur une ressource du
 *      tenant B (IDOR horizontal).
 *   2. Escalade verticale : un étudiant tente une action réservée aux
 *      enseignants/admins (création de cours, gestion d'utilisateurs...).
 *
 * Complète le flow E2E #211 n°5 (isolation) avec un fichier dédié exigé par
 * l'issue #212, centré sur les frontières d'autorisation.
 *
 * @see app/Http/Middleware/EnsureRole.php
 * @see app/Models/Traits/BelongsToInstitution.php
 */
final class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $instA;
    private Institution $instB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instA = Institution::factory()->create(['slug' => 'school-a']);
        $this->instB = Institution::factory()->create(['slug' => 'school-b']);
    }

    private function user(Institution $inst, string $role): User
    {
        return User::factory()->create([
            'institution_id' => $inst->id,
            'role' => $role,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. Cross-tenant (IDOR horizontal)
    // ─────────────────────────────────────────────────────────────────────

    public function test_student_cannot_read_cross_tenant_lesson(): void
    {
        $teacherA = $this->user($this->instA, 'enseignant');
        $lessonA = Lesson::factory()->create([
            'institution_id' => $this->instA->id,
            'enseignant_id' => $teacherA->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($this->user($this->instB, 'etudiant'));

        $this->getJson("/api/lessons/{$lessonA->id}")
            ->assertStatus(404);
    }

    public function test_teacher_cannot_modify_cross_tenant_quiz(): void
    {
        $teacherA = $this->user($this->instA, 'enseignant');
        $quizA = Quiz::factory()->create([
            'institution_id' => $this->instA->id,
            'created_by' => $teacherA->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($this->user($this->instB, 'enseignant'));

        $update = $this->putJson("/api/quizzes/{$quizA->id}", [
            'title' => 'Quiz détourné par un autre tenant',
        ]);
        $this->assertContains($update->status(), [403, 404]);

        $publish = $this->postJson("/api/quizzes/{$quizA->id}/publish");
        $this->assertContains($publish->status(), [403, 404]);

        $delete = $this->deleteJson("/api/quizzes/{$quizA->id}");
        $this->assertContains($delete->status(), [403, 404]);

        // Le quiz de A est resté intact (draft, jamais publié/supprimé).
        $this->assertDatabaseHas('quizzes', [
            'id' => $quizA->id,
            'status' => 'draft',
        ]);
    }

    public function test_admin_cannot_act_cross_tenant(): void
    {
        $teacherA = $this->user($this->instA, 'enseignant');
        $lessonA = Lesson::factory()->create([
            'institution_id' => $this->instA->id,
            'enseignant_id' => $teacherA->id,
            'status' => 'draft',
            'published_at' => null,
        ]);

        // Un admin du tenant B ne peut pas toucher au cours de A.
        Sanctum::actingAs($this->user($this->instB, 'admin'));

        $publish = $this->postJson("/api/lessons/{$lessonA->id}/publish");
        $this->assertContains($publish->status(), [403, 404]);

        $delete = $this->deleteJson("/api/lessons/{$lessonA->id}");
        $this->assertContains($delete->status(), [403, 404]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. Escalade verticale de privilèges
    // ─────────────────────────────────────────────────────────────────────

    public function test_student_cannot_create_lessons(): void
    {
        $classe = \App\Models\Classe::factory()->create([
            'institution_id' => $this->instA->id,
        ]);

        Sanctum::actingAs($this->user($this->instA, 'etudiant'));

        $this->postJson('/api/lessons', [
            'title' => 'Cours créé par un étudiant',
            'type' => 'cours',
            'classe_id' => $classe->id,
        ])->assertStatus(403);
    }

    public function test_student_cannot_create_or_publish_quizzes(): void
    {
        Sanctum::actingAs($this->user($this->instA, 'etudiant'));

        $this->postJson('/api/quizzes', [
            'title' => 'Quiz pirate',
            'type' => 'formative',
        ])->assertStatus(403);
    }

    public function test_student_cannot_reach_teacher_only_quiz_attempts_listing(): void
    {
        $teacher = $this->user($this->instA, 'enseignant');
        $quiz = Quiz::factory()->create([
            'institution_id' => $this->instA->id,
            'created_by' => $teacher->id,
        ]);

        Sanctum::actingAs($this->user($this->instA, 'etudiant'));

        // GET /quizzes/{quiz}/attempts est réservé enseignant/coordinateur/admin.
        $this->getJson("/api/quizzes/{$quiz->id}/attempts")
            ->assertStatus(403);
    }

    public function test_role_field_cannot_be_escalated_via_mass_assignment(): void
    {
        // Un étudiant ne doit pas pouvoir s'auto-promouvoir admin en injectant
        // un champ `role` dans une requête d'écriture quelconque.
        $student = $this->user($this->instA, 'etudiant');
        $classe = \App\Models\Classe::factory()->create([
            'institution_id' => $this->instA->id,
        ]);

        Sanctum::actingAs($student);

        // Tentative : créer un topic en injectant role=admin dans le body.
        $this->postJson('/api/forum/topics', [
            'title' => 'Topic avec injection de rôle',
            'content' => 'Tentative d\'escalade via mass-assignment du champ role.',
            'role' => 'admin',
            'institution_id' => $this->instB->id,
        ])->assertStatus(201);

        // Le rôle et l'institution de l'étudiant n'ont PAS changé.
        $student->refresh();
        $this->assertSame('etudiant', $student->role);
        $this->assertSame($this->instA->id, $student->institution_id);
    }

    public function test_student_cannot_grade_quiz_attempts(): void
    {
        $teacher = $this->user($this->instA, 'enseignant');
        $quiz = Quiz::factory()->create([
            'institution_id' => $this->instA->id,
            'created_by' => $teacher->id,
        ]);
        $attempt = \App\Models\QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $this->user($this->instA, 'etudiant')->id,
            'institution_id' => $this->instA->id,
            'status' => 'submitted',
        ]);

        // Un étudiant ne peut pas noter (action enseignant).
        Sanctum::actingAs($this->user($this->instA, 'etudiant'));

        $this->postJson("/api/quiz-attempts/{$attempt->id}/grade", [
            'points_earned' => 100,
        ])->assertStatus(403);
    }
}
