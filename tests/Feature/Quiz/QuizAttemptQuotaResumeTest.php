<?php

declare(strict_types=1);

namespace Tests\Feature\Quiz;

use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * #581 — quota `max_attempts` du quiz : reprise, comptage et course.
 *
 * État constaté avant correctif (sonde exécutée, `max_attempts = 1`) :
 *
 * ```
 * [P3] start #1 status=201
 * [P3] start #2 status=201
 * [P3] start #3 status=201
 * [P3] attempts persisted=3 (max_attempts=1)
 * ```
 *
 * `attemptsCountForUser()` ne comptait que `submitted`/`graded` : trois onglets
 * ouvraient trois tentatives, toutes soumissibles, et la MEILLEURE des trois
 * notes était retenue.
 *
 * Décision produit (tranchée avec l'utilisateur, cf. requirements §2) : une
 * tentative en cours est **reprise**, pas abandonnée. L'abandonner repartirait
 * d'un `started_at` neuf et donnerait un temps illimité sur un quiz chronométré.
 */
final class QuizAttemptQuotaResumeTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
    }

    private function publishedQuiz(int $maxAttempts = 1, ?int $durationMinutes = null): Quiz
    {
        $teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
        ]);
        $lesson = Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'enseignant_id' => $teacher->id,
            'status' => 'published',
        ]);

        return Quiz::factory()->create([
            'institution_id' => $this->institution->id,
            'lesson_id' => $lesson->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
            'max_attempts' => $maxAttempts,
            'duration_minutes' => $durationMinutes,
        ]);
    }

    private function start(Quiz $quiz): TestResponse
    {
        return $this->postJson("/api/quizzes/{$quiz->id}/start");
    }

    // ───────────────── R1.1 — la reprise ferme le contournement ─────────────────

    public function test_trois_demarrages_rendent_la_meme_tentative(): void
    {
        $quiz = $this->publishedQuiz(maxAttempts: 1);
        Sanctum::actingAs($this->student);

        $first = $this->start($quiz);
        $first->assertStatus(201);
        $attemptId = $first->json('data.attempt.id');

        foreach ([2, 3] as $_) {
            $resumed = $this->start($quiz);
            $resumed->assertStatus(200);
            $this->assertSame($attemptId, $resumed->json('data.attempt.id'));
        }

        $this->assertSame(1, QuizAttempt::count(), 'Trois onglets ne doivent ouvrir qu une tentative.');
    }

    public function test_la_reprise_conserve_le_chronometre_dorigine(): void
    {
        $quiz = $this->publishedQuiz(maxAttempts: 1, durationMinutes: 30);
        Sanctum::actingAs($this->student);

        $this->start($quiz)->assertStatus(201);
        $attempt = QuizAttempt::firstOrFail();
        // La tentative a déjà consommé 20 des 30 minutes.
        $attempt->forceFill(['started_at' => now()->subMinutes(20)])->save();

        $resumed = $this->start($quiz);

        $resumed->assertStatus(200);
        $remaining = (int) $resumed->json('data.time_remaining');
        $this->assertGreaterThan(
            0,
            $remaining,
            'Le temps restant doit rester positif — la tentative n est pas terminée.',
        );
        $this->assertLessThanOrEqual(
            10 * 60,
            $remaining,
            'Un chronomètre remis à neuf donnerait 30 min : reprendre ne doit pas redonner de temps.',
        );
    }

    public function test_apres_reprise_la_seconde_soumission_est_refusee(): void
    {
        $quiz = $this->publishedQuiz(maxAttempts: 1);
        $question = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'institution_id' => $this->institution->id,
        ]);
        Sanctum::actingAs($this->student);

        $attemptId = (int) $this->start($quiz)->json('data.attempt.id');
        $this->start($quiz)->assertStatus(200);

        // `answers` est `required|array` : un tableau vide serait rejeté en 422
        // pour une raison sans rapport avec le quota.
        $answers = ['answers' => [(string) $question->id => 'A']];

        $this->postJson("/api/quiz-attempts/{$attemptId}/submit", $answers)
            ->assertStatus(200);

        $this->postJson("/api/quiz-attempts/{$attemptId}/submit", $answers)
            ->assertStatus(422);

        $this->assertSame(1, QuizAttempt::count());
    }

    // ───────────────── R2.1 / R2.2 — comptage honnête ─────────────────

    public function test_une_tentative_en_cours_est_comptee_mais_reste_reprenable(): void
    {
        $quiz = $this->publishedQuiz(maxAttempts: 1);
        Sanctum::actingAs($this->student);
        $this->start($quiz)->assertStatus(201);

        $listing = $this->getJson('/api/quizzes?lesson_id=' . $quiz->lesson_id);

        $listing->assertStatus(200);
        $row = collect($listing->json('data.data') ?? $listing->json('data'))
            ->first(static fn (array $item): bool => (int) $item['id'] === (int) $quiz->id);

        $this->assertNotNull($row, 'Le quiz doit apparaître dans la liste.');
        $this->assertSame(1, (int) $row['user_attempts_count'], 'La tentative en cours doit être comptée.');
        $this->assertTrue(
            (bool) $row['user_can_attempt'],
            'Une tentative reprenable doit laisser user_can_attempt vrai, sinon l interface ment.',
        );
    }

    public function test_une_tentative_abandonnee_ne_consomme_pas_de_quota(): void
    {
        $quiz = $this->publishedQuiz(maxAttempts: 1);
        $this->seedAttempt($quiz, attemptNumber: 1, status: 'abandoned');
        Sanctum::actingAs($this->student);

        $this->start($quiz)->assertStatus(201);

        $this->assertSame(
            [1, 2],
            QuizAttempt::orderBy('attempt_number')->pluck('attempt_number')
                ->map(static fn ($n): int => (int) $n)->all(),
        );
    }

    public function test_le_quota_refuse_apres_une_tentative_notee(): void
    {
        $quiz = $this->publishedQuiz(maxAttempts: 1);
        $this->seedAttempt($quiz, attemptNumber: 1, status: 'graded');
        Sanctum::actingAs($this->student);

        $this->start($quiz)->assertStatus(403);

        $this->assertSame(1, QuizAttempt::count());
    }

    // ───────────────── R3.1 — course : reprise ou 409, jamais 500 ─────────────────

    public function test_une_course_perdue_sur_une_tentative_ouverte_donne_une_reprise(): void
    {
        $quiz = $this->publishedQuiz(maxAttempts: 3);
        $this->insertCompetingAttemptOnNextCreate($quiz, 'in_progress');
        Sanctum::actingAs($this->student);

        $this->start($quiz)->assertStatus(200);

        $this->assertSame(1, QuizAttempt::count());
    }

    public function test_une_course_perdue_sans_tentative_reprenable_donne_409(): void
    {
        $quiz = $this->publishedQuiz(maxAttempts: 3);
        $this->insertCompetingAttemptOnNextCreate($quiz, 'graded');
        Sanctum::actingAs($this->student);

        $this->start($quiz)->assertStatus(409);

        $this->assertSame(1, QuizAttempt::count());
    }

    // ───────────────── R4.1 — l'app doit voir ce que l'index voit ─────────────────

    /**
     * `quiz_attempts_quiz_id_user_id_attempt_number_unique` ignore
     * `institution_id`, alors que `$quiz->attempts()` porte le global scope.
     * Une tentative héritée à `institution_id = NULL` est donc invisible au
     * calcul mais bien vue par l'index → 409 définitif.
     *
     * Le vrai jeton Bearer est indispensable : `Sanctum::actingAs()` n'en pose
     * aucun, `ResolveInstitution` laisserait le tenant nul et le global scope
     * serait désactivé — le test passerait sans rien prouver.
     */
    public function test_une_tentative_heritee_sans_institution_ne_bloque_pas(): void
    {
        $quiz = $this->publishedQuiz(maxAttempts: 3);
        $this->seedAttempt($quiz, attemptNumber: 1, status: 'graded', institutionId: null);

        $token = $this->student->createToken('legacy-tenant-581')->plainTextToken;
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/quizzes/{$quiz->id}/start");

        $response->assertStatus(201);
        $this->assertSame(
            [1, 2],
            DB::table('quiz_attempts')->orderBy('attempt_number')
                ->pluck('attempt_number')->map(static fn ($n): int => (int) $n)->all(),
        );
    }

    // ───────────────────────────── Helpers ─────────────────────────────

    private function seedAttempt(
        Quiz $quiz,
        int $attemptNumber,
        string $status,
        ?int $institutionId = -1,
    ): void {
        QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'user_id' => $this->student->id,
            'attempt_number' => $attemptNumber,
            'status' => $status,
            'started_at' => now()->subHour(),
            'submitted_at' => $status === 'in_progress' ? null : now()->subMinutes(30),
            'institution_id' => $institutionId === -1 ? $this->institution->id : $institutionId,
        ]);
    }

    /**
     * Simule la requête concurrente : au moment précis où le service s'apprête
     * à insérer, une autre a déjà pris le même `attempt_number`. Insertion via
     * le query builder pour ne pas re-déclencher l'événement.
     */
    private function insertCompetingAttemptOnNextCreate(Quiz $quiz, string $status): void
    {
        QuizAttempt::creating(function (QuizAttempt $attempt) use ($quiz, $status): void {
            static $done = false;
            if ($done) {
                return;
            }
            $done = true;

            DB::table('quiz_attempts')->insert([
                'quiz_id' => $quiz->id,
                'user_id' => $this->student->id,
                'attempt_number' => $attempt->attempt_number,
                'status' => $status,
                'started_at' => now(),
                'submitted_at' => $status === 'in_progress' ? null : now(),
                'institution_id' => $this->institution->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
