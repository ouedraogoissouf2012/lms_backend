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
 * Integration tests for `POST /api/quiz-attempts/{id}/grade`.
 *
 * Comble le gap test-coverage identifié dans l'audit total post-TIER 2 :
 * `QuizAttemptController::gradeAttempt` permet à un enseignant de noter
 * manuellement (override) une tentative — mutation des notes, **sans
 * couverture test feature** (seul `GradeAttemptRequest` validation testée).
 *
 * Tests :
 *  - Happy path : créateur du quiz note manuellement → 200, points_earned set
 *  - Admin peut noter n'importe quel quiz → 200
 *  - Non-créateur non-admin → 403 (FormRequest authorize)
 *  - Étudiant ne peut pas s'auto-noter → 403
 *  - Cross-tenant : enseignant de B essaie de noter attempt d'un quiz de A → 403
 *  - Points négatifs → 422 (validation rule min:0)
 *  - Points > points_possible → 422 (validation rule max)
 *
 * @see app/Http/Controllers/API/Quiz/QuizAttemptController.php::gradeAttempt
 * @see app/Http/Requests/GradeAttemptRequest.php
 */
final class GradeAttemptHappyPathTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institutionA;
    private Institution $institutionB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institutionA = Institution::factory()->create(['slug' => 'school-a']);
        $this->institutionB = Institution::factory()->create(['slug' => 'school-b']);
    }

    private function createTeacher(Institution $inst): User
    {
        return User::factory()->create([
            'institution_id' => $inst->id,
            'role' => 'enseignant',
        ]);
    }

    private function createStudent(Institution $inst): User
    {
        return User::factory()->create([
            'institution_id' => $inst->id,
            'role' => 'etudiant',
        ]);
    }

    private function createQuizAndAttempt(Institution $inst, User $creator, User $student): QuizAttempt
    {
        $quiz = Quiz::factory()->create([
            'institution_id' => $inst->id,
            'created_by' => $creator->id,
            'passing_score' => 50,
        ]);

        return QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $student->id,
            'institution_id' => $inst->id,
            'status' => 'submitted',
            'points_possible' => 100,
            'points_earned' => 0,
        ]);
    }

    public function test_quiz_creator_can_grade_attempt(): void
    {
        $teacher = $this->createTeacher($this->institutionA);
        $student = $this->createStudent($this->institutionA);
        $attempt = $this->createQuizAndAttempt($this->institutionA, $teacher, $student);

        Sanctum::actingAs($teacher);

        $response = $this->postJson("/api/quiz-attempts/{$attempt->id}/grade", [
            'points_earned' => 75,
            'feedback' => 'Bonne tentative — quelques erreurs sur les essais.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $attempt->refresh();
        $this->assertSame(75.0, (float) $attempt->points_earned);
        $this->assertSame(75.0, (float) $attempt->score);
        $this->assertTrue((bool) $attempt->passed);
        $this->assertSame('graded', $attempt->status);
        $this->assertSame($teacher->id, $attempt->graded_by);
        $this->assertNotNull($attempt->graded_at);
        $this->assertSame('Bonne tentative — quelques erreurs sur les essais.', $attempt->teacher_feedback);
    }

    public function test_admin_can_grade_any_quiz_attempt(): void
    {
        $teacher = $this->createTeacher($this->institutionA);
        $student = $this->createStudent($this->institutionA);
        $admin = User::factory()->create([
            'institution_id' => $this->institutionA->id,
            'role' => 'superAdmin',
        ]);

        $attempt = $this->createQuizAndAttempt($this->institutionA, $teacher, $student);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/quiz-attempts/{$attempt->id}/grade", [
            'points_earned' => 60,
            'feedback' => null,
        ]);

        $response->assertStatus(200);

        $attempt->refresh();
        $this->assertSame(60.0, (float) $attempt->points_earned);
        $this->assertSame($admin->id, $attempt->graded_by);
    }

    public function test_non_creator_teacher_cannot_grade(): void
    {
        $creator = $this->createTeacher($this->institutionA);
        $otherTeacher = $this->createTeacher($this->institutionA);
        $student = $this->createStudent($this->institutionA);

        $attempt = $this->createQuizAndAttempt($this->institutionA, $creator, $student);

        Sanctum::actingAs($otherTeacher);

        $response = $this->postJson("/api/quiz-attempts/{$attempt->id}/grade", [
            'points_earned' => 50,
        ]);

        $response->assertStatus(403);
    }

    public function test_student_cannot_self_grade(): void
    {
        $teacher = $this->createTeacher($this->institutionA);
        $student = $this->createStudent($this->institutionA);
        $attempt = $this->createQuizAndAttempt($this->institutionA, $teacher, $student);

        Sanctum::actingAs($student);

        $response = $this->postJson("/api/quiz-attempts/{$attempt->id}/grade", [
            'points_earned' => 100,
        ]);

        // Étudiant bloqué par middleware role:enseignant,coordinateur,admin sur la route
        $this->assertContains($response->status(), [403]);
    }

    /**
     * Cross-tenant : enseignant de B essaie de noter un attempt d'un quiz
     * créé par un enseignant de A. Bloqué par `GradeAttemptRequest::authorize`
     * qui vérifie `quiz->created_by === user->id || isAdmin()`.
     */
    public function test_cross_tenant_teacher_cannot_grade(): void
    {
        $teacherA = $this->createTeacher($this->institutionA);
        $teacherB = $this->createTeacher($this->institutionB);
        $studentA = $this->createStudent($this->institutionA);

        $attempt = $this->createQuizAndAttempt($this->institutionA, $teacherA, $studentA);

        Sanctum::actingAs($teacherB);

        $response = $this->postJson("/api/quiz-attempts/{$attempt->id}/grade", [
            'points_earned' => 100,
        ]);

        $response->assertStatus(403);

        // Confirmation : l'attempt n'a pas été modifié
        $attempt->refresh();
        $this->assertNull($attempt->graded_by);
    }

    public function test_negative_points_rejected(): void
    {
        $teacher = $this->createTeacher($this->institutionA);
        $student = $this->createStudent($this->institutionA);
        $attempt = $this->createQuizAndAttempt($this->institutionA, $teacher, $student);

        Sanctum::actingAs($teacher);

        $response = $this->postJson("/api/quiz-attempts/{$attempt->id}/grade", [
            'points_earned' => -10,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['points_earned']);
    }

    public function test_points_exceeding_max_rejected(): void
    {
        $teacher = $this->createTeacher($this->institutionA);
        $student = $this->createStudent($this->institutionA);
        $attempt = $this->createQuizAndAttempt($this->institutionA, $teacher, $student);

        Sanctum::actingAs($teacher);

        // points_possible = 100 (cf. createQuizAndAttempt). 150 doit casser la rule max.
        $response = $this->postJson("/api/quiz-attempts/{$attempt->id}/grade", [
            'points_earned' => 150,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['points_earned']);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $teacher = $this->createTeacher($this->institutionA);
        $student = $this->createStudent($this->institutionA);
        $attempt = $this->createQuizAndAttempt($this->institutionA, $teacher, $student);

        $response = $this->postJson("/api/quiz-attempts/{$attempt->id}/grade", [
            'points_earned' => 50,
        ]);

        $response->assertStatus(401);
    }
}
