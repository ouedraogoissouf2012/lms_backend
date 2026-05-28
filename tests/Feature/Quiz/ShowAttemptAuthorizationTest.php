<?php

declare(strict_types=1);

namespace Tests\Feature\Quiz;

use App\Models\Institution;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Integration tests for `GET /api/quiz-attempts/{id}` authorization.
 *
 * Verifies the tenant × role × ownership matrix through `ShowAttemptRequest`
 * (using `ChecksForumAuthorization` with 2-call short-circuit for the
 * dual-ownership case: attempt-owner OR quiz-creator).
 *
 * Note: `coordinateur` is intentionally rejected here (regression check) —
 * coordinators supervise but do not view per-student answer details.
 *
 * Reference: #87 — Quiz IDOR cross-tenant fix (the MEDIUM bug pattern).
 *
 * @see app/Http/Requests/ShowAttemptRequest.php
 * @see app/Http/Requests/Concerns/ChecksForumAuthorization.php
 * @see .claude/specs/quiz-idor-get-attempts/design.md §3.2
 */
final class ShowAttemptAuthorizationTest extends TestCase
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

    private function createUser(Institution $inst, string $role): User
    {
        return User::factory()->create([
            'institution_id' => $inst->id,
            'role' => $role,
        ]);
    }

    private function createQuiz(Institution $inst, User $creator): Quiz
    {
        return Quiz::factory()->create([
            'created_by' => $creator->id,
            'institution_id' => $inst->id,
        ]);
    }

    private function createAttempt(Quiz $quiz, User $student): QuizAttempt
    {
        return QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $student->id,
            'institution_id' => $quiz->institution_id,
        ]);
    }

    public function test_attempt_owner_can_view_intra_tenant(): void
    {
        $teacher = $this->createUser($this->instA, 'enseignant');
        $student = $this->createUser($this->instA, 'etudiant');
        $quiz = $this->createQuiz($this->instA, $teacher);
        $attempt = $this->createAttempt($quiz, $student);

        Sanctum::actingAs($student);

        $response = $this->getJson("/api/quiz-attempts/{$attempt->id}");

        $response->assertStatus(200);
    }

    public function test_quiz_creator_can_view_attempt_intra_tenant(): void
    {
        $teacher = $this->createUser($this->instA, 'enseignant');
        $student = $this->createUser($this->instA, 'etudiant');
        $quiz = $this->createQuiz($this->instA, $teacher);
        $attempt = $this->createAttempt($quiz, $student);

        Sanctum::actingAs($teacher);

        $response = $this->getJson("/api/quiz-attempts/{$attempt->id}");

        $response->assertStatus(200);
    }

    public function test_admin_can_view_attempt_intra_tenant(): void
    {
        $teacher = $this->createUser($this->instA, 'enseignant');
        $student = $this->createUser($this->instA, 'etudiant');
        $quiz = $this->createQuiz($this->instA, $teacher);
        $attempt = $this->createAttempt($quiz, $student);
        $admin = $this->createUser($this->instA, 'admin');

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/quiz-attempts/{$attempt->id}");

        $response->assertStatus(200);
    }

    public function test_coordinateur_cannot_view_attempt_intra_tenant(): void
    {
        $teacher = $this->createUser($this->instA, 'enseignant');
        $student = $this->createUser($this->instA, 'etudiant');
        $quiz = $this->createQuiz($this->instA, $teacher);
        $attempt = $this->createAttempt($quiz, $student);
        $coordinator = $this->createUser($this->instA, 'coordinateur');

        Sanctum::actingAs($coordinator);

        $response = $this->getJson("/api/quiz-attempts/{$attempt->id}");

        // Coordinateur is intentionally NOT in moderatorRoles for showAttempt
        // (supervises but does not view per-student answer details).
        $response->assertStatus(403);
    }

    public function test_non_owner_student_cannot_view_attempt_intra_tenant(): void
    {
        $teacher = $this->createUser($this->instA, 'enseignant');
        $student = $this->createUser($this->instA, 'etudiant');
        $quiz = $this->createQuiz($this->instA, $teacher);
        $attempt = $this->createAttempt($quiz, $student);

        $otherStudent = $this->createUser($this->instA, 'etudiant');
        Sanctum::actingAs($otherStudent);

        $response = $this->getJson("/api/quiz-attempts/{$attempt->id}");

        $response->assertStatus(403);
    }

    public function test_admin_cannot_view_attempt_cross_tenant(): void
    {
        $teacher = $this->createUser($this->instA, 'enseignant');
        $student = $this->createUser($this->instA, 'etudiant');
        $quiz = $this->createQuiz($this->instA, $teacher);
        $attempt = $this->createAttempt($quiz, $student);

        $adminB = $this->createUser($this->instB, 'admin');
        Sanctum::actingAs($adminB);

        $response = $this->getJson("/api/quiz-attempts/{$attempt->id}");

        self::assertContains($response->status(), [403, 404]);
    }

    public function test_supradmin_can_view_attempt_cross_tenant(): void
    {
        $teacher = $this->createUser($this->instA, 'enseignant');
        $student = $this->createUser($this->instA, 'etudiant');
        $quiz = $this->createQuiz($this->instA, $teacher);
        $attempt = $this->createAttempt($quiz, $student);

        $supradmin = User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]);
        Sanctum::actingAs($supradmin);

        $response = $this->getJson("/api/quiz-attempts/{$attempt->id}");

        $response->assertStatus(200);
    }
}
