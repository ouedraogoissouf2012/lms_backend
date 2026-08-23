<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dashboard;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Dashboard\StudentDashboardService;
use App\Services\Notification\NotificationPresenter;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests unitaires du bloc `progression.quizzes` de
 * {@see StudentDashboardService} (issue #608).
 *
 * Le service interrogeait `avg('percentage')` sur `quiz_attempts` — colonne
 * INEXISTANTE — filtré par `status = 'completed'` — valeur ABSENTE de
 * l'énumération. SQLite masquait les deux (littéral chaîne, filtre mort) ;
 * MySQL renvoie `1054 Unknown column 'percentage'` → **HTTP 500 en
 * production** sur `GET /api/dashboard/student`.
 *
 * Les tests d'acceptation existants (`DashboardStudentResponseTest`) exercent
 * un étudiant SANS aucune tentative : ils verrouillent l'enveloppe JSON mais
 * ne peuvent pas prouver que les agrégats sont justes. D'où ce fichier.
 *
 * Les attendus sont alignés sur le service frère déjà correct et testé,
 * `TeacherDashboardServiceTest::test_quiz_block_uses_real_columns_and_attempt_statuses`
 * (#364/#401) : mêmes tentatives, mêmes chiffres.
 *
 * @see app/Services/Dashboard/StudentDashboardService.php
 * @see app/Models/QuizAttempt.php  scopeCompleted()
 */
final class StudentDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private StudentDashboardService $service;

    private Institution $institution;

    private User $student;

    private Quiz $quiz;

    /**
     * `quiz_attempts` porte un unique (quiz_id, user_id, attempt_number) :
     * des tentatives successives du MÊME étudiant sur le MÊME quiz doivent
     * être numérotées, comme en production.
     */
    private int $nextAttemptNumber = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new StudentDashboardService(new NotificationPresenter);

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
        $this->app->make(TenantManager::class)->set($this->institution);

        $teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
        ]);
        $this->student = User::factory()->student()->create([
            'institution_id' => $this->institution->id,
        ]);
        $this->quiz = Quiz::factory()->forTeacher($teacher)->create([
            'institution_id' => $this->institution->id,
        ]);
    }

    /**
     * Une tentative soumise en attente de correction (score `null`) et deux
     * tentatives corrigées (80 et 60) : 3 tentatives terminées, moyenne 70.0.
     *
     * `AVG` ignore les `NULL` en SQL — la tentative non corrigée compte dans
     * `total_attempts` sans tirer la moyenne vers le bas. C'est exactement la
     * sémantique retenue par `TeacherDashboardService`.
     */
    public function test_quiz_block_uses_real_columns_and_attempt_statuses(): void
    {
        $this->attemptForStudent(['status' => 'submitted', 'score' => null, 'graded_at' => null]);
        $this->attemptForStudent(['status' => 'graded', 'score' => 80]);
        $this->attemptForStudent(['status' => 'graded', 'score' => 60]);

        $quizzes = $this->service->buildDashboard($this->student)['progression']['quizzes'];

        $this->assertSame(3, $quizzes['total_attempts']);
        $this->assertSame(70.0, $quizzes['average_score']);
    }

    /**
     * Les tentatives non terminées (`in_progress`, `abandoned`) et celles d'un
     * AUTRE étudiant ne doivent ni compter ni peser sur la moyenne.
     */
    public function test_quiz_block_ignores_other_students_and_unfinished_attempts(): void
    {
        $this->attemptForStudent(['status' => 'graded', 'score' => 40]);
        $this->attemptForStudent(['status' => 'in_progress', 'score' => null]);
        $this->attemptForStudent(['status' => 'abandoned', 'score' => 100]);

        $otherStudent = User::factory()->student()->create([
            'institution_id' => $this->institution->id,
        ]);
        QuizAttempt::factory()->forQuiz($this->quiz)->byUser($otherStudent)->create([
            'status' => 'graded',
            'score' => 100,
        ]);

        $quizzes = $this->service->buildDashboard($this->student)['progression']['quizzes'];

        $this->assertSame(1, $quizzes['total_attempts']);
        $this->assertSame(40.0, $quizzes['average_score']);
    }

    /**
     * Aucune tentative : le bloc reste présent et neutre (pas de `null`, pas
     * de division par zéro) — le contrat de l'enveloppe est préservé.
     */
    public function test_quiz_block_is_neutral_without_any_attempt(): void
    {
        $quizzes = $this->service->buildDashboard($this->student)['progression']['quizzes'];

        $this->assertSame(['total_attempts', 'average_score'], array_keys($quizzes));
        $this->assertSame(0, $quizzes['total_attempts']);
        $this->assertSame(0.0, $quizzes['average_score']);
    }

    /**
     * CARACTÉRISATION d'un cas limite assumé : une tentative soumise mais pas
     * encore corrigée (score `null`) compte dans `total_attempts`, et `AVG`
     * n'ayant aucune valeur à moyenner, `average_score` retombe sur `0.0` via
     * le `?? 0`. L'étudiant lit donc « 1 tentative — 0 % » tant que la
     * correction n'est pas faite.
     *
     * Conservé tel quel : `TeacherDashboardService` se comporte à l'identique
     * (`TeacherDashboardServiceTest:201`) et renvoyer `null` changerait le type
     * de la clé dans une réponse déjà consommée. Documenté ici pour que ce
     * soit une décision, pas un accident — le rendre distinguable de « 0 % réel »
     * demande une clé supplémentaire, donc une évolution de contrat.
     */
    public function test_average_score_falls_back_to_zero_while_attempts_await_grading(): void
    {
        $this->attemptForStudent(['status' => 'submitted', 'score' => null, 'graded_at' => null]);

        $quizzes = $this->service->buildDashboard($this->student)['progression']['quizzes'];

        $this->assertSame(1, $quizzes['total_attempts']);
        $this->assertSame(0.0, $quizzes['average_score']);
    }

    /**
     * `upcoming_quizzes` exclut les quiz que l'étudiant a déjà terminés.
     *
     * Le `whereDoesntHave('attempts', …)` filtrait sur `status = 'completed'`
     * — même valeur fantôme : la sous-requête ne remontait jamais rien, donc
     * l'exclusion était toujours vraie et un quiz déjà passé restait affiché
     * comme « à venir ». Pas de crash SQL, d'où l'invisibilité totale.
     */
    public function test_upcoming_quizzes_excludes_quizzes_the_student_already_finished(): void
    {
        $done = $this->publishedQuizForStudentClass('Quiz déjà passé');
        $todo = $this->publishedQuizForStudentClass('Quiz à faire');

        QuizAttempt::factory()->forQuiz($done)->byUser($this->student)->create([
            'status' => 'graded',
            'score' => 90,
        ]);

        $titles = $this->service->buildDashboard($this->student)['upcoming_quizzes']
            ->pluck('title')
            ->all();

        $this->assertSame(['Quiz à faire'], $titles);
        $this->assertNotContains($done->title, $titles);
    }

    /**
     * Un quiz publié dans une classe où l'étudiant du test est inscrit.
     */
    private function publishedQuizForStudentClass(string $title): Quiz
    {
        $classe = Classe::factory()->create(['institution_id' => $this->institution->id]);
        $classe->etudiants()->attach($this->student->id, ['statut' => 'actif']);

        return Quiz::factory()->create([
            'institution_id' => $this->institution->id,
            'classe_id' => $classe->id,
            'title' => $title,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function attemptForStudent(array $attributes): void
    {
        QuizAttempt::factory()
            ->forQuiz($this->quiz)
            ->byUser($this->student)
            ->create($attributes + ['attempt_number' => $this->nextAttemptNumber++]);
    }
}
