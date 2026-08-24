<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * #540 — tentatives d'évaluation : chemin nominal, identité de l'étudiant et
 * course concurrente sur l'unique `eval_sub_unique`.
 *
 * État constaté avant correctif (sonde exécutée) :
 *
 * ```
 * [P1] start status=200
 * [P1] row after start: student_id=NULL klassci=5555 attempt=1
 * [P1] submit status=500 body={"success":false,"message":"Erreur lors de la soumission"}
 * ```
 *
 * `/start` créait la soumission avec `student_id = NULL` ; `/submit` la
 * cherchait par `student_id`, ne la trouvait jamais et en recréait une avec
 * `attempt = 1` — violant l'unique et remontant en 500. Le parcours nominal de
 * l'évaluation en ligne était donc cassé à 100 %, indépendamment de toute
 * concurrence.
 *
 * @see app/Services/Evaluation/Student/EvaluationAttemptOpener.php
 */
final class EvaluationAttemptQuotaRaceTest extends TestCase
{
    use RefreshDatabase;

    private const KLASSCI_STUDENT_ID = 5555;

    private Institution $institution;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->student = User::factory()->student()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => self::KLASSCI_STUDENT_ID,
            'klassci_token' => 'student-klassci-token',
        ]);
    }

    /** KLASSCI répond « aucune fenêtre configurée » : le gate laisse passer. */
    private function fakeOpenWindow(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->andReturn(['data' => []]);
        });
    }

    private function publishedEvaluation(int $maxAttempts = 3): Evaluation
    {
        $evaluation = Evaluation::factory()->planifiee()->create([
            'institution_id' => $this->institution->id,
            'klassci_evaluation_id' => 9001,
            'max_attempts' => $maxAttempts,
        ]);
        EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $this->institution->id,
            'type' => 'qcm',
        ]);

        return $evaluation;
    }

    // ─────────────────── R2.1 / R2.2 — chemin nominal réparé ───────────────────

    public function test_demarrer_puis_soumettre_reussit_et_ne_cree_quune_seule_soumission(): void
    {
        $this->fakeOpenWindow();
        $evaluation = $this->publishedEvaluation();
        $question = $evaluation->questions()->first();
        Sanctum::actingAs($this->student);

        $this->postJson("/api/evaluations/{$evaluation->id}/start")->assertStatus(200);

        $submit = $this->postJson("/api/evaluations/{$evaluation->id}/submit", [
            'answers' => [$question->id => 'A'],
        ]);

        $submit->assertStatus(201)->assertJsonPath('success', true);
        $this->assertSame(
            1,
            EvaluationSubmission::where('evaluation_id', $evaluation->id)->count(),
            'La soumission créée au démarrage doit être réutilisée, pas dupliquée.',
        );
        $this->assertSame('soumis', EvaluationSubmission::first()?->status);
    }

    /**
     * Une évaluation à plusieurs tentatives doit être utilisable de bout en
     * bout. Le quota appartient au service (`max_attempts`) — la FormRequest ne
     * doit PAS bloquer sur « une soumission finalisée existe », sinon `/start`
     * ouvre la tentative 2 en 200 et `/submit` la refuse en 403, rendant tout
     * `max_attempts > 1` inutilisable et le refus `max_attempts` inatteignable
     * depuis `/submit`.
     */
    public function test_une_seconde_tentative_autorisee_est_soumissible(): void
    {
        $this->fakeOpenWindow();
        $evaluation = $this->publishedEvaluation(maxAttempts: 2);
        $question = $evaluation->questions()->first();
        Sanctum::actingAs($this->student);

        $this->postJson("/api/evaluations/{$evaluation->id}/start")->assertStatus(200);
        $this->postJson("/api/evaluations/{$evaluation->id}/submit", [
            'answers' => [$question->id => 'A'],
        ])->assertStatus(201);

        $this->postJson("/api/evaluations/{$evaluation->id}/start")->assertStatus(200);
        $this->postJson("/api/evaluations/{$evaluation->id}/submit", [
            'answers' => [$question->id => 'B'],
        ])->assertStatus(201);

        $this->assertSame(
            [1, 2],
            EvaluationSubmission::orderBy('attempt')->pluck('attempt')
                ->map(static fn ($n): int => (int) $n)->all(),
        );
    }

    /** Passé le quota, la soumission est refusée avec le message métier. */
    public function test_la_soumission_au_dela_du_quota_est_refusee_en_403(): void
    {
        $this->fakeOpenWindow();
        $evaluation = $this->publishedEvaluation(maxAttempts: 1);
        $question = $evaluation->questions()->first();
        Sanctum::actingAs($this->student);

        $this->postJson("/api/evaluations/{$evaluation->id}/submit", [
            'answers' => [$question->id => 'A'],
        ])->assertStatus(201);

        $refused = $this->postJson("/api/evaluations/{$evaluation->id}/submit", [
            'answers' => [$question->id => 'B'],
        ]);

        $refused->assertStatus(403)->assertJsonPath('success', false);
        $this->assertSame(1, EvaluationSubmission::count());
    }

    public function test_le_demarrage_renseigne_les_deux_identites_de_letudiant(): void
    {
        $this->fakeOpenWindow();
        $evaluation = $this->publishedEvaluation();
        Sanctum::actingAs($this->student);

        $this->postJson("/api/evaluations/{$evaluation->id}/start")->assertStatus(200);

        $submission = EvaluationSubmission::firstOrFail();
        $this->assertSame($this->student->id, $submission->student_id);
        $this->assertSame(self::KLASSCI_STUDENT_ID, (int) $submission->klassci_etudiant_id);
        $this->assertSame($this->institution->id, $submission->institution_id);
    }

    // ───────────────── R2.3 — étudiant non synchronisé KLASSCI ─────────────────

    public function test_un_etudiant_sans_identifiant_klassci_est_refuse_sans_insertion(): void
    {
        $this->fakeOpenWindow();
        $evaluation = $this->publishedEvaluation();
        $this->student->forceFill(['klassci_id' => null])->save();
        Sanctum::actingAs($this->student->fresh());

        $response = $this->postJson("/api/evaluations/{$evaluation->id}/start");

        $response->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => 'Utilisateur sans ID KLASSCI synchronisé',
            ]);
        $this->assertSame(0, EvaluationSubmission::count());
    }

    // ─────────────── R4.1 — numérotation robuste aux trous ───────────────

    /**
     * `count + 1` re-proposait un numéro déjà pris dès qu'une tentative
     * intermédiaire avait été supprimée : deux tentatives (1 et 3) donnaient
     * `count + 1 = 3`, en collision frontale avec l'unique. `max + 1` donne 4.
     */
    public function test_le_numero_de_tentative_survit_a_un_trou_dans_la_serie(): void
    {
        $this->fakeOpenWindow();
        $evaluation = $this->publishedEvaluation(maxAttempts: 5);
        $this->seedSubmission($evaluation, attempt: 1, status: 'soumis');
        $this->seedSubmission($evaluation, attempt: 3, status: 'corrige');
        Sanctum::actingAs($this->student);

        $this->postJson("/api/evaluations/{$evaluation->id}/start")->assertStatus(200);

        $this->assertSame(
            [1, 3, 4],
            EvaluationSubmission::orderBy('attempt')->pluck('attempt')
                ->map(static fn ($n): int => (int) $n)->all(),
        );
    }

    public function test_le_quota_refuse_le_demarrage_au_dela_du_maximum(): void
    {
        $this->fakeOpenWindow();
        $evaluation = $this->publishedEvaluation(maxAttempts: 2);
        $this->seedSubmission($evaluation, attempt: 1, status: 'soumis');
        $this->seedSubmission($evaluation, attempt: 2, status: 'corrige');
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$evaluation->id}/start");

        $response->assertStatus(403)->assertJsonPath('success', false);
        $this->assertSame(2, EvaluationSubmission::count());
    }

    // ────────────── R3.1 / R3.3 — course : reprise ou 409, jamais 500 ──────────────

    /**
     * Double-clic : une tentative concurrente a déjà pris le numéro et reste
     * ouverte. Le perdant doit récupérer la tentative gagnante (200, reprise),
     * pas une erreur — c'est le comportement attendu d'un simple double-clic.
     */
    public function test_une_course_perdue_sur_une_tentative_ouverte_donne_une_reprise(): void
    {
        $this->fakeOpenWindow();
        $evaluation = $this->publishedEvaluation();
        $this->insertCompetingSubmissionOnNextCreate($evaluation, 'en_cours');
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$evaluation->id}/start");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Reprise de la tentative en cours');
        $this->assertSame(1, EvaluationSubmission::count());
    }

    /**
     * Course perdue contre une tentative déjà finalisée : rien à reprendre. Le
     * conflit est signalé en 409 métier — jamais en 500.
     */
    public function test_une_course_perdue_sans_tentative_reprenable_donne_409(): void
    {
        $this->fakeOpenWindow();
        $evaluation = $this->publishedEvaluation(maxAttempts: 5);
        $this->insertCompetingSubmissionOnNextCreate($evaluation, 'soumis');
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$evaluation->id}/start");

        $response->assertStatus(409)->assertJsonPath('success', false);
        $this->assertSame(1, EvaluationSubmission::count());
    }

    // ───────────────────────────── Helpers ─────────────────────────────

    private function seedSubmission(Evaluation $evaluation, int $attempt, string $status): void
    {
        EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $this->institution->id,
            'student_id' => $this->student->id,
            'klassci_etudiant_id' => self::KLASSCI_STUDENT_ID,
            'attempt' => $attempt,
            'status' => $status,
        ]);
    }

    /**
     * Simule la requête concurrente : au moment précis où le service s'apprête
     * à insérer, une autre a déjà pris le même `attempt`. Insertion via le
     * query builder pour ne pas re-déclencher l'événement.
     */
    private function insertCompetingSubmissionOnNextCreate(Evaluation $evaluation, string $status): void
    {
        EvaluationSubmission::creating(function (EvaluationSubmission $submission) use ($evaluation, $status): void {
            static $done = false;
            if ($done) {
                return;
            }
            $done = true;

            DB::table('evaluation_submissions')->insert([
                'evaluation_id' => $evaluation->id,
                'student_id' => $this->student->id,
                'klassci_etudiant_id' => self::KLASSCI_STUDENT_ID,
                'institution_id' => $this->institution->id,
                'attempt' => $submission->attempt,
                'status' => $status,
                'started_at' => now(),
                'submitted_at' => $status === 'en_cours' ? null : now(),
                'synced_to_klassci' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
