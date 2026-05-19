<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #123 — IDOR cross-student via klassci_etudiant_id lu du request body / URL.
 *
 * Verifies that:
 *  - `POST /api/evaluations/{id}/start` derives the student identity from the
 *    Sanctum token, ignoring any `klassci_etudiant_id` field in the body.
 *  - The route `/api/evaluations/student/{klassciEtudiantId}` is removed
 *    (only `/api/evaluations/student` without param is allowed).
 *  - `StartEvaluationRequest::authorize()` blocks non-student roles.
 *
 * Spec: `.claude/specs/klassci-etudiant-id-from-token/`
 *
 * @see \App\Http\Controllers\API\EvaluationController::startEvaluation
 * @see \App\Http\Controllers\API\EvaluationController::myEvaluations
 * @see \App\Http\Requests\StartEvaluationRequest
 */
final class KlassciEtudiantIdFromTokenTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('PostgreSQL PDO driver not available (CI-only test).');
        }

        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
    }

    private function student(int $klassciId, array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'institution_id' => $this->institution->id,
            'klassci_id'     => $klassciId,
            'role'           => 'etudiant',
            'klassci_role'   => 'etudiant',
        ], $extra));
    }

    private function publishedEvaluation(array $overrides = []): Evaluation
    {
        $evaluation = Evaluation::factory()->create(array_merge([
            'institution_id' => $this->institution->id,
            'is_published'   => true,
            'is_locked'      => false,
            'status'         => 'en_cours',
            'max_attempts'   => 3,
        ], $overrides));

        // L'évaluation doit avoir au moins 1 question (sinon startEvaluation
        // retourne 422 « pas encore de questions »).
        EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->id,
            'question'      => 'Question test',
        ]);

        return $evaluation;
    }

    // REQ-6 #1 — Body ignored: A's klassci_id used even when body forges B's.
    public function test_start_evaluation_ignores_klassci_etudiant_id_from_body(): void
    {
        $studentA = $this->student(klassciId: 42);
        $evaluation = $this->publishedEvaluation();

        Sanctum::actingAs($studentA);

        $response = $this->postJson("/api/evaluations/{$evaluation->id}/start", [
            'klassci_etudiant_id' => 999,    // attacker forge B's id
        ]);

        $response->assertStatus(200);

        $submission = EvaluationSubmission::where('evaluation_id', $evaluation->id)->latest()->first();
        self::assertNotNull($submission);
        self::assertSame(
            42,
            $submission->klassci_etudiant_id,
            'Submission MUST be created under the authenticated student id (42), not the forged body value (999).',
        );
    }

    // REQ-6 #2 — Active submission scoping: A's existing in-progress submission
    // is returned, not a forged-id one.
    public function test_start_evaluation_reuses_active_submission_of_authenticated_user_only(): void
    {
        $studentA = $this->student(klassciId: 42);
        $evaluation = $this->publishedEvaluation();

        $existingSubmission = EvaluationSubmission::create([
            'evaluation_id'       => $evaluation->id,
            'klassci_etudiant_id' => 42,
            'attempt'             => 1,
            'status'              => 'en_cours',
            'started_at'          => now(),
        ]);

        Sanctum::actingAs($studentA);

        $response = $this->postJson("/api/evaluations/{$evaluation->id}/start", [
            'klassci_etudiant_id' => 999,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $existingSubmission->id);

        // No new submission was created.
        $count = EvaluationSubmission::where('evaluation_id', $evaluation->id)->count();
        self::assertSame(1, $count);
    }

    // REQ-6 #3 — Role guard: teacher gets 403 before reaching the controller.
    public function test_start_evaluation_blocked_for_non_student(): void
    {
        $teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id'     => 7777,
            'role'           => 'enseignant',
            'klassci_role'   => 'enseignant',
        ]);
        $evaluation = $this->publishedEvaluation();

        Sanctum::actingAs($teacher);

        $this->postJson("/api/evaluations/{$evaluation->id}/start", [])
            ->assertStatus(403);
    }

    // REQ-6 #4 — Max attempts scoping: A's count is used, not forged-id's.
    public function test_start_evaluation_max_attempts_counts_authenticated_user_only(): void
    {
        $studentA = $this->student(klassciId: 42);
        $evaluation = $this->publishedEvaluation(['max_attempts' => 3]);

        // A has 3 completed submissions (max reached).
        foreach (['soumis', 'soumis', 'corrige'] as $i => $status) {
            EvaluationSubmission::create([
                'evaluation_id'       => $evaluation->id,
                'klassci_etudiant_id' => 42,
                'attempt'             => $i + 1,
                'status'              => $status,
                'started_at'          => now()->subDays($i + 1),
                'submitted_at'        => now()->subDays($i + 1)->addHour(),
            ]);
        }

        // The "forged" target user B (id=999) has zero submissions — but the
        // attacker should be blocked by A's count, not B's.
        Sanctum::actingAs($studentA);

        $response = $this->postJson("/api/evaluations/{$evaluation->id}/start", [
            'klassci_etudiant_id' => 999,
        ]);

        $response->assertStatus(403);
        self::assertStringContainsString('maximum', strtolower($response->json('message') ?? ''));
    }

    // REQ-6 #5 — Removed route: GET /api/evaluations/student/{X} returns 404.
    public function test_get_student_evaluations_with_param_returns_404(): void
    {
        $studentA = $this->student(klassciId: 42);
        Sanctum::actingAs($studentA);

        $this->getJson('/api/evaluations/student/999')
            ->assertStatus(404);
    }

    // REQ-6 #6 — myEvaluations route still works and returns data for token owner.
    public function test_get_my_evaluations_route_returns_evaluations_for_authenticated_user(): void
    {
        $studentA = $this->student(klassciId: 42);
        Sanctum::actingAs($studentA);

        // The endpoint hits KLASSCI for the dashboard — in this test we don't
        // mock the proxy, so the response will be a 500 wrapped in a structured
        // error payload. The point is to prove the route EXISTS (not 404) and
        // does not require a URL param.
        $response = $this->getJson('/api/evaluations/student');

        // Route exists, klassci.sync may yield 500 due to mocked KLASSCI absence,
        // but it must NOT be 404 (which would mean the route disappeared).
        self::assertNotSame(404, $response->getStatusCode());
    }
}
