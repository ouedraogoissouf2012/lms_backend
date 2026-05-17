<?php

declare(strict_types=1);

namespace Tests\Feature\Quiz;

use App\Models\Institution;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Integration tests for `GET /api/quizzes/{quiz}/attempts` authorization.
 *
 * Verifies the tenant × role × ownership matrix through `GetQuizAttemptsRequest`
 * (using `ChecksForumAuthorization`). Defense-in-depth above the
 * `BelongsToInstitution` global scope.
 *
 * Reference: #87 — Quiz IDOR cross-tenant fix (the HIGH).
 *
 * @see app/Http/Requests/GetQuizAttemptsRequest.php
 * @see app/Http/Requests/Concerns/ChecksForumAuthorization.php
 * @see .claude/specs/quiz-idor-get-attempts/design.md §3.1
 */
final class GetQuizAttemptsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $instA;
    private Institution $instB;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('PostgreSQL PDO driver not available (CI-only test).');
        }

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

    public function test_quiz_creator_can_list_attempts_intra_tenant(): void
    {
        $teacher = $this->createUser($this->instA, 'enseignant');
        $quiz = $this->createQuiz($this->instA, $teacher);

        Sanctum::actingAs($teacher);

        $response = $this->getJson("/api/quizzes/{$quiz->id}/attempts");

        $response->assertStatus(200);
    }

    public function test_admin_can_list_attempts_intra_tenant(): void
    {
        $teacher = $this->createUser($this->instA, 'enseignant');
        $quiz = $this->createQuiz($this->instA, $teacher);
        $admin = $this->createUser($this->instA, 'admin');

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/quizzes/{$quiz->id}/attempts");

        $response->assertStatus(200);
    }

    public function test_coordinateur_can_list_attempts_intra_tenant(): void
    {
        $teacher = $this->createUser($this->instA, 'enseignant');
        $quiz = $this->createQuiz($this->instA, $teacher);
        $coordinator = $this->createUser($this->instA, 'coordinateur');

        Sanctum::actingAs($coordinator);

        $response = $this->getJson("/api/quizzes/{$quiz->id}/attempts");

        $response->assertStatus(200);
    }

    public function test_admin_cannot_list_attempts_cross_tenant(): void
    {
        $teacher = $this->createUser($this->instA, 'enseignant');
        $quiz = $this->createQuiz($this->instA, $teacher);
        $adminB = $this->createUser($this->instB, 'admin');

        Sanctum::actingAs($adminB);

        $response = $this->getJson("/api/quizzes/{$quiz->id}/attempts");

        self::assertContains($response->status(), [403, 404]);
    }

    public function test_coordinateur_cannot_list_attempts_cross_tenant(): void
    {
        $teacher = $this->createUser($this->instA, 'enseignant');
        $quiz = $this->createQuiz($this->instA, $teacher);
        $coordB = $this->createUser($this->instB, 'coordinateur');

        Sanctum::actingAs($coordB);

        $response = $this->getJson("/api/quizzes/{$quiz->id}/attempts");

        self::assertContains($response->status(), [403, 404]);
    }

    public function test_non_creator_teacher_cannot_list_attempts_intra_tenant(): void
    {
        $creator = $this->createUser($this->instA, 'enseignant');
        $quiz = $this->createQuiz($this->instA, $creator);
        $otherTeacher = $this->createUser($this->instA, 'enseignant');

        Sanctum::actingAs($otherTeacher);

        $response = $this->getJson("/api/quizzes/{$quiz->id}/attempts");

        $response->assertStatus(403);
    }

    public function test_student_cannot_list_attempts_blocked_by_role_middleware(): void
    {
        $teacher = $this->createUser($this->instA, 'enseignant');
        $quiz = $this->createQuiz($this->instA, $teacher);
        $student = $this->createUser($this->instA, 'etudiant');

        Sanctum::actingAs($student);

        $response = $this->getJson("/api/quizzes/{$quiz->id}/attempts");

        // `role:enseignant,coordinateur,admin` middleware filters before authorize().
        $response->assertStatus(403);
    }

    public function test_supradmin_can_list_attempts_cross_tenant(): void
    {
        $teacher = $this->createUser($this->instA, 'enseignant');
        $quiz = $this->createQuiz($this->instA, $teacher);

        $supradmin = User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]);
        Sanctum::actingAs($supradmin);

        $response = $this->getJson("/api/quizzes/{$quiz->id}/attempts");

        $response->assertStatus(200);
    }
}
