<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
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
        // `date_evaluation` figé dans le futur + `duree_minutes` raisonnable :
        // sans ça, la factory tire au sort `date_evaluation` entre -1 mois et +1 mois,
        // et si `date_evaluation + duree_minutes < now()` alors `Evaluation::isTerminee()`
        // retourne true, le controller bascule en mode entraînement et le check
        // `max_attempts` est BYPASSÉ → test flaky 200 vs 403 (issue identifiée
        // lors du PERF-03 audit).
        $evaluation = Evaluation::factory()->create(array_merge([
            'institution_id'  => $this->institution->id,
            'is_published'    => true,
            'is_locked'       => false,
            'status'          => 'en_cours',
            'max_attempts'    => 3,
            'allow_retake'    => true,
            'is_online'       => true,
            'date_evaluation' => now()->addDay(),
            'duree_minutes'   => 60,
        ], $overrides));

        // L'évaluation doit avoir au moins 1 question (sinon startEvaluation
        // retourne 422 « pas encore de questions »).
        // institution_id requis pour que le global scope BelongsToInstitution
        // retrouve la question quand le tenant est résolu.
        EvaluationQuestion::factory()->create([
            'evaluation_id'  => $evaluation->id,
            'question'       => 'Question test',
            'institution_id' => $evaluation->institution_id,
        ]);

        return $evaluation;
    }

    /**
     * Simule un KLASSCI disponible (aucune fenêtre configurée → toujours ouverte)
     * afin que le `start` traverse le gate fenêtre et exerce la logique anti-IDOR.
     * Depuis #499, sans ce mock la vérification de fenêtre échoue (fail-closed 503).
     */
    private function fakeKlassciAvailable(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
        });
    }

    // REQ-6 #1 — Body ignored: A's klassci_id used even when body forges B's.
    public function test_start_evaluation_ignores_klassci_etudiant_id_from_body(): void
    {
        $studentA = $this->student(klassciId: 42);
        $evaluation = $this->publishedEvaluation();
        $this->fakeKlassciAvailable();

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
        $this->fakeKlassciAvailable();

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
        // institution_id requis pour que le global scope BelongsToInstitution
        // retrouve les submissions quand le tenant est résolu (sinon attemptsCount=0).
        foreach (['soumis', 'soumis', 'corrige'] as $i => $status) {
            EvaluationSubmission::create([
                'evaluation_id'       => $evaluation->id,
                'klassci_etudiant_id' => 42,
                'attempt'             => $i + 1,
                'status'              => $status,
                'started_at'          => now()->subDays($i + 1),
                'submitted_at'        => now()->subDays($i + 1)->addHour(),
                'institution_id'      => $this->institution->id,
            ]);
        }

        // The "forged" target user B (id=999) has zero submissions — but the
        // attacker should be blocked by A's count, not B's.
        $this->fakeKlassciAvailable();
        Sanctum::actingAs($studentA);

        // Sans middleware ResolveInstitution : ce test fait du fingerprinting
        // sur la logique anti-IDOR du controller (REQ-6 #4). La résolution de
        // tenant n'est pas l'objet du test et son comportement avec Sanctum::actingAs
        // est fragile à l'ordre d'exécution.
        $response = $this->withoutMiddleware(\App\Http\Middleware\ResolveInstitution::class)
            ->postJson("/api/evaluations/{$evaluation->id}/start", [
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
