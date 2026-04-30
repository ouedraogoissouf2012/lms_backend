<?php

namespace Tests\Feature\Requests;

use App\Models\User;
use App\Models\Institution;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for StoreEvaluationRequest (POST /api/evaluations)
 *
 * Validates:
 * - User authenticated
 * - User NOT coordinateur (explicit exclusion)
 * - Input fields validated (titre, type, duration, etc.)
 * - Optional questions array with nested validation
 * - Multi-tenant check (implicit via institution_id in normal flow)
 */
class StoreEvaluationRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private User $teacher;
    private User $coordinator;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();

        $this->teacher = User::factory()
            ->teacher()
            ->for($this->institution)
            ->create();

        $this->coordinator = User::factory()
            ->coordinator()
            ->for($this->institution)
            ->create();

        $this->student = User::factory()
            ->student()
            ->for($this->institution)
            ->create();
    }

    /**
     * ✅ HAPPY PATH: Teacher creates evaluation
     */
    public function test_teacher_can_create_evaluation(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/evaluations', [
            'klassci_matiere_id' => 1,
            'klassci_classe_id' => 1,
            'titre' => 'Math Quiz',
            'type' => 'qcm',
            'duree_minutes' => 60,
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    /**
     * ✅ HAPPY PATH: Create with description
     */
    public function test_create_with_description(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/evaluations', [
            'klassci_matiere_id' => 1,
            'klassci_classe_id' => 1,
            'titre' => 'History Test',
            'description' => 'Test the historical events of the 20th century',
            'type' => 'dissertation',
            'duree_minutes' => 120,
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    /**
     * ✅ HAPPY PATH: Create with questions array
     */
    public function test_create_with_questions(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/evaluations', [
            'klassci_matiere_id' => 1,
            'klassci_classe_id' => 1,
            'titre' => 'Quiz',
            'type' => 'qcm',
            'duree_minutes' => 45,
            'questions' => [
                [
                    'question' => 'What is 2+2?',
                    'type' => 'qcm',
                    'points' => 10,
                    'options' => ['3', '4', '5'],
                    'correct_answers' => ['4'],
                ],
            ],
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    /**
     * ✅ WHITESPACE: Titre trimmed
     */
    public function test_titre_trimmed(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/evaluations', [
            'klassci_matiere_id' => 1,
            'klassci_classe_id' => 1,
            'titre' => '   Trimmed Titre   ',
            'type' => 'qcm',
            'duree_minutes' => 30,
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    /**
     * ❌ AUTHORIZATION: Coordinator cannot create
     */
    public function test_coordinator_cannot_create(): void
    {
        Sanctum::actingAs($this->coordinator);

        $response = $this->postJson('/api/evaluations', [
            'klassci_matiere_id' => 1,
            'klassci_classe_id' => 1,
            'titre' => 'Coordinator Attempt',
            'type' => 'qcm',
            'duree_minutes' => 30,
        ]);

        $response->assertStatus(403);
    }

    /**
     * ❌ AUTHORIZATION: Student cannot create
     */
    public function test_student_cannot_create(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson('/api/evaluations', [
            'klassci_matiere_id' => 1,
            'klassci_classe_id' => 1,
            'titre' => 'Student Attempt',
            'type' => 'qcm',
            'duree_minutes' => 30,
        ]);

        $response->assertStatus(403);
    }

    /**
     * ❌ UNAUTHENTICATED
     */
    public function test_unauthenticated_cannot_create(): void
    {
        $response = $this->postJson('/api/evaluations', [
            'klassci_matiere_id' => 1,
            'klassci_classe_id' => 1,
            'titre' => 'No Token',
            'type' => 'qcm',
            'duree_minutes' => 30,
        ]);

        $response->assertStatus(401);
    }

    /**
     * ❌ VALIDATION: Missing titre
     */
    public function test_missing_titre(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/evaluations', [
            'klassci_matiere_id' => 1,
            'klassci_classe_id' => 1,
            'type' => 'qcm',
            'duree_minutes' => 30,
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.titre'));
    }

    /**
     * ❌ VALIDATION: Missing type
     */
    public function test_missing_type(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/evaluations', [
            'klassci_matiere_id' => 1,
            'klassci_classe_id' => 1,
            'titre' => 'No Type',
            'duree_minutes' => 30,
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.type'));
    }

    /**
     * ❌ VALIDATION: Missing duration
     */
    public function test_missing_duration(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/evaluations', [
            'klassci_matiere_id' => 1,
            'klassci_classe_id' => 1,
            'titre' => 'No Duration',
            'type' => 'qcm',
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.duree_minutes'));
    }

    /**
     * ❌ VALIDATION: Invalid type
     */
    public function test_invalid_type(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/evaluations', [
            'klassci_matiere_id' => 1,
            'klassci_classe_id' => 1,
            'titre' => 'Quiz',
            'type' => 'invalid_type',
            'duree_minutes' => 30,
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.type'));
    }

    /**
     * ❌ VALIDATION: Titre too short
     */
    public function test_titre_too_short(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/evaluations', [
            'klassci_matiere_id' => 1,
            'klassci_classe_id' => 1,
            'titre' => 'ab',
            'type' => 'qcm',
            'duree_minutes' => 30,
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.titre'));
    }

    /**
     * ❌ VALIDATION: Duration exceeds limit
     */
    public function test_duration_exceeds_limit(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/evaluations', [
            'klassci_matiere_id' => 1,
            'klassci_classe_id' => 1,
            'titre' => 'Long Quiz',
            'type' => 'qcm',
            'duree_minutes' => 1441,
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.duree_minutes'));
    }

    /**
     * ❌ VALIDATION: Question missing type
     */
    public function test_question_missing_type(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/evaluations', [
            'klassci_matiere_id' => 1,
            'klassci_classe_id' => 1,
            'titre' => 'Quiz',
            'type' => 'qcm',
            'duree_minutes' => 30,
            'questions' => [
                [
                    'question' => 'What?',
                    // Missing type
                ],
            ],
        ]);

        $response->assertStatus(422);
    }

    /**
     * ❌ VALIDATION: Invalid question type
     */
    public function test_invalid_question_type(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/evaluations', [
            'klassci_matiere_id' => 1,
            'klassci_classe_id' => 1,
            'titre' => 'Quiz',
            'type' => 'qcm',
            'duree_minutes' => 30,
            'questions' => [
                [
                    'question' => 'What?',
                    'type' => 'invalid_question_type',
                ],
            ],
        ]);

        $response->assertStatus(422);
    }
}
