<?php

namespace Tests\Feature\Requests;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\EvaluationQuestion;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for SubmitEvaluationRequest (POST /api/evaluations/{id}/submit)
 *
 * ## Contrat des réponses (MAP)
 * `answers` est une MAP indexée par question_id : `{ "<id>": <réponse> }`.
 * La réponse est une chaîne (qcm / vrai_faux / reponse_courte / dissertation) ou
 * un tableau de chaînes (qcm_multiple). Une valeur vide (`""` / `[]`) = question
 * non répondue (tolérée). Ce contrat est aligné sur le frontend
 * (`useTakeEvaluation.js`), le service de correction et le quiz (#564).
 *
 * ## Authorization Model
 * authorize() vérifie : utilisateur authentifié + étudiant, évaluation publiée,
 * échéance non dépassée, non déjà soumis. Sinon → 401/403.
 *
 * ## 10-year perspective
 * Ces tests documentent la machine à états de soumission + le contrat MAP.
 * Toute régression du format des réponses est attrapée immédiatement.
 */
class SubmitEvaluationRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private User $student;
    private User $teacher;
    private Evaluation $evaluation;
    private EvaluationQuestion $question1;
    private EvaluationQuestion $question2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();

        $this->student = User::factory()
            ->student()
            ->for($this->institution)
            ->create();

        $this->teacher = User::factory()
            ->teacher()
            ->for($this->institution)
            ->create();

        // Create published evaluation with future deadline
        $this->evaluation = Evaluation::factory()
            ->for($this->institution)
            ->state([
                'status' => 'published',
                'is_published' => true,
                'deadline_at' => now()->addDays(7),
            ])
            ->create();

        $this->question1 = EvaluationQuestion::factory()
            ->for($this->evaluation)
            ->state(['question' => 'What is 2+2?', 'type' => 'qcm', 'correct_answers' => ['4'], 'points' => 10])
            ->create();

        $this->question2 = EvaluationQuestion::factory()
            ->for($this->evaluation)
            ->state(['question' => 'Capital of France?', 'type' => 'qcm', 'correct_answers' => ['Paris'], 'points' => 10])
            ->create();
    }

    /**
     * ✅ HAPPY PATH: Valid evaluation submission (MAP)
     */
    public function test_valid_submission_passes(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                $this->question1->id => '4',
                $this->question2->id => 'Paris',
            ],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('evaluation_submissions', [
            'evaluation_id' => $this->evaluation->id,
            'student_id' => $this->student->id,
        ]);
    }

    /**
     * ✅ HAPPY PATH: Single answer submission
     */
    public function test_single_answer_submission_passes(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                $this->question1->id => '4',
            ],
        ]);

        $response->assertStatus(201);
    }

    /**
     * ✅ TIMESTAMP: submitted_at with ISO 8601 format
     */
    public function test_submission_with_timestamp_passes(): void
    {
        Sanctum::actingAs($this->student);
        $timestamp = now()->format('Y-m-d\\TH:i:s\\Z');

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                $this->question1->id => '4',
            ],
            'submitted_at' => $timestamp,
        ]);

        $response->assertStatus(201);
    }

    /**
     * ✅ EDGE CASE: Answer with leading/trailing spaces is trimmed (map-safe)
     */
    public function test_answer_with_spaces_is_trimmed(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                $this->question1->id => '   4   ',
            ],
        ]);

        $response->assertStatus(201);
        $submission = EvaluationSubmission::where('evaluation_id', $this->evaluation->id)->first();
        // Le trim map-safe doit stocker la valeur nettoyée sous la bonne clé.
        $this->assertSame('4', $submission->answers[$this->question1->id]);
    }

    /**
     * ❌ VALIDATION: Missing answers fails
     */
    public function test_missing_answers_fails(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            // No answers
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.answers'));
    }

    /**
     * ❌ VALIDATION: Empty answers map fails (min:1)
     */
    public function test_empty_answers_map_fails(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [],
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.answers'));
    }

    /**
     * ❌ VALIDATION: Ancien format LISTE rejeté proprement (jamais 0 silencieux ni 500)
     */
    public function test_obsolete_list_payload_is_rejected(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                ['question_id' => $this->question1->id, 'answer' => '4'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertNull(
            EvaluationSubmission::where('evaluation_id', $this->evaluation->id)->first(),
            'Un payload liste ne doit créer aucune soumission.'
        );
    }

    /**
     * ❌ VALIDATION: Valeur booléenne rejetée (ferme le type-juggling, cf. #498)
     */
    public function test_boolean_answer_value_is_rejected(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                $this->question1->id => true,
            ],
        ]);

        $response->assertStatus(422);
    }

    /**
     * ✅ VALIDATION: Valeurs vides (question non répondue) tolérées
     */
    public function test_empty_answer_values_are_allowed(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                $this->question1->id => '',
                $this->question2->id => '',
            ],
        ]);

        $response->assertStatus(201);
    }

    /**
     * ❌ DOS PREVENTION: Answer too long fails
     */
    public function test_answer_exceeding_max_length_fails(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                $this->question1->id => str_repeat('a', 10001),
            ],
        ]);

        $response->assertStatus(422);
    }

    /**
     * ❌ AUTHORIZATION: Unauthenticated user cannot submit
     */
    public function test_unauthenticated_cannot_submit(): void
    {
        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                $this->question1->id => '4',
            ],
        ]);

        $response->assertStatus(401);
    }

    /**
     * ❌ AUTHORIZATION: Teacher cannot submit (only students)
     */
    public function test_teacher_cannot_submit_evaluation(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                $this->question1->id => '4',
            ],
        ]);

        $response->assertStatus(403);
    }

    /**
     * ❌ STATE: Draft evaluation cannot be submitted
     */
    public function test_draft_evaluation_cannot_be_submitted(): void
    {
        $draft_evaluation = Evaluation::factory()
            ->for($this->institution)
            ->state(['status' => 'draft', 'is_published' => false])
            ->create();

        $draft_question = EvaluationQuestion::factory()
            ->for($draft_evaluation)
            ->create();

        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$draft_evaluation->id}/submit", [
            'answers' => [
                $draft_question->id => '4',
            ],
        ]);

        $response->assertStatus(403);
    }

    /**
     * ❌ DEADLINE: Expired deadline cannot submit
     */
    public function test_expired_deadline_cannot_submit(): void
    {
        $expired_evaluation = Evaluation::factory()
            ->for($this->institution)
            ->state([
                'status' => 'published',
                'deadline_at' => now()->subDays(1), // Yesterday
            ])
            ->create();

        $expired_question = EvaluationQuestion::factory()
            ->for($expired_evaluation)
            ->create();

        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$expired_evaluation->id}/submit", [
            'answers' => [
                $expired_question->id => '4',
            ],
        ]);

        $response->assertStatus(403);
    }

    /**
     * ❌ DUPLICATE: Already submitted cannot submit again
     */
    /**
     * Fixture rendue COHÉRENTE en #540. Elle prétendait « l'étudiant a déjà
     * soumis » tout en créant la soumission avec un `klassci_etudiant_id`
     * aléatoire (défaut de la factory), sans rapport avec l'étudiant, et sur
     * une évaluation dont `max_attempts` était tiré au hasard entre 1 et 3.
     * Elle ne passait que grâce à un Check `authorize()` qui ignorait le quota
     * — celui-là même qui rendait `max_attempts > 1` inutilisable (#540).
     *
     * Le refus est désormais porté par le service : la fixture pose donc
     * `max_attempts = 1` et rattache la soumission au vrai identifiant KLASSCI
     * de l'étudiant. Même intention, cette fois réellement exercée.
     *
     * Le CODE passe de 403 à **422** : l'étudiant a déjà soumis et n'a aucune
     * tentative ouverte, `/submit` lui demande donc de démarrer explicitement la
     * suivante plutôt que d'en rouvrir une — un double-clic dépensait sinon un
     * essai en silence. Le quota lui-même reste refusé en 403, mais sur
     * `/start`, seul chemin d'ouverture (#540).
     */
    public function test_already_submitted_cannot_resubmit(): void
    {
        $this->evaluation->forceFill(['max_attempts' => 1])->save();

        EvaluationSubmission::factory()
            ->for($this->evaluation)
            ->for($this->student, 'student')
            ->create(['klassci_etudiant_id' => $this->student->klassci_id]);

        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                $this->question1->id => '4',
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            1,
            EvaluationSubmission::where('evaluation_id', $this->evaluation->id)->count(),
            'Une re-soumission refusée ne doit créer aucune tentative.',
        );
    }

    /**
     * ✅ TIMESTAMP: Submission without timestamp uses current time
     */
    public function test_submission_without_timestamp_defaults_to_now(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                $this->question1->id => '4',
            ],
            // No submitted_at
        ]);

        $response->assertStatus(201);
        $submission = EvaluationSubmission::latest()->first();
        $this->assertNotNull($submission->submitted_at);
    }

    /**
     * ❌ TIMESTAMP: Invalid ISO 8601 format fails
     */
    public function test_invalid_timestamp_format_fails(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                $this->question1->id => '4',
            ],
            'submitted_at' => '2026-04-30 14:30:00', // Invalid format (should be ISO 8601)
        ]);

        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertNotEmpty($errors['submitted_at'] ?? null);
    }

    /**
     * ✅ MULTIPLE: Multiple valid answers together (map)
     */
    public function test_multiple_answers_all_valid(): void
    {
        $question3 = EvaluationQuestion::factory()->for($this->evaluation)->create();
        $question4 = EvaluationQuestion::factory()->for($this->evaluation)->create();

        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                $this->question1->id => 'Answer 1',
                $this->question2->id => 'Answer 2',
                $question3->id => 'Answer 3',
                $question4->id => 'Answer 4',
            ],
        ]);

        $response->assertStatus(201);
    }
}
