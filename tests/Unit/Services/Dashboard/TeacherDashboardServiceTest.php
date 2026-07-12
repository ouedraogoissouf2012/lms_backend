<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dashboard;

use App\Models\ForumTopic;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\Dashboard\TeacherDashboardService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests unitaires de {@see TeacherDashboardService} (issue #364 — TDD :
 * écrits AVANT l'extraction de la logique hors de
 * `DashboardTeacherController::teacher`).
 *
 * Le service produit le payload du dashboard enseignant : cours, étudiants
 * actifs, quiz à corriger et topics forum. #401 couvre le bloc quiz avec les
 * colonnes réellement migrées.
 */
final class TeacherDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private TeacherDashboardService $service;

    private Institution $institutionA;

    private Institution $institutionB;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TeacherDashboardService;
        $this->institutionA = Institution::factory()->create(['slug' => 'school-a']);
        $this->institutionB = Institution::factory()->create(['slug' => 'school-b']);

        $this->teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institutionA->id,
        ]);

        $this->app->make(TenantManager::class)->set($this->institutionA);
    }

    public function test_lesson_counts_and_top_lessons_reflect_only_the_teacher_content(): void
    {
        $published = Lesson::factory()->forTeacher($this->teacher)->published()->create([
            'institution_id' => $this->institutionA->id,
            'title' => 'Cours vedette',
        ]);
        Lesson::factory()->forTeacher($this->teacher)->draft()->create([
            'institution_id' => $this->institutionA->id,
        ]);

        // Contenu d'un AUTRE enseignant de la même institution : jamais compté.
        $otherTeacher = User::factory()->teacher()->create([
            'institution_id' => $this->institutionA->id,
        ]);
        Lesson::factory()->forTeacher($otherTeacher)->published()->create([
            'institution_id' => $this->institutionA->id,
        ]);

        $studentStarted = User::factory()->student()->create([
            'institution_id' => $this->institutionA->id,
        ]);
        $studentDone = User::factory()->student()->create([
            'institution_id' => $this->institutionA->id,
        ]);
        LessonProgress::factory()->inProgress()->create([
            'lesson_id' => $published->id,
            'user_id' => $studentStarted->id,
            'progress_percentage' => 40,
        ]);
        LessonProgress::factory()->completed()->create([
            'lesson_id' => $published->id,
            'user_id' => $studentDone->id,
        ]);

        $dashboard = $this->service->buildDashboard($this->teacher);

        $this->assertSame(2, $dashboard['lessons']['total']);
        $this->assertSame(1, $dashboard['lessons']['published']);
        $this->assertSame(1, $dashboard['lessons']['draft']);

        $topLessons = $dashboard['lessons']['top_lessons'];
        $this->assertCount(1, $topLessons);
        $this->assertSame(
            [
                'lesson_id' => $published->id,
                'title' => 'Cours vedette',
                'students_started' => 2,
                'students_completed' => 1,
                // avg(40, 100) = 70.0 — withAvg ne filtre pas par statut (verbatim).
                'average_progress' => 70.0,
            ],
            $topLessons[0]
        );
    }

    public function test_active_students_counts_distinct_recent_learners_only(): void
    {
        $lesson = Lesson::factory()->forTeacher($this->teacher)->published()->create([
            'institution_id' => $this->institutionA->id,
        ]);

        $recentStudent = User::factory()->student()->create([
            'institution_id' => $this->institutionA->id,
        ]);
        $staleStudent = User::factory()->student()->create([
            'institution_id' => $this->institutionA->id,
        ]);

        LessonProgress::factory()->inProgress()->create([
            'lesson_id' => $lesson->id,
            'user_id' => $recentStudent->id,
            'last_accessed_at' => now()->subDays(2),
        ]);
        // Accès trop ancien (> 7 jours) : exclu du compteur.
        LessonProgress::factory()->inProgress()->create([
            'lesson_id' => $lesson->id,
            'user_id' => $staleStudent->id,
            'last_accessed_at' => now()->subDays(10),
        ]);

        $dashboard = $this->service->buildDashboard($this->teacher);

        $this->assertSame(1, $dashboard['students']['active_last_7_days']);
    }

    public function test_forum_block_lists_unresolved_open_topics_of_teacher_lessons(): void
    {
        $lesson = Lesson::factory()->forTeacher($this->teacher)->published()->create([
            'institution_id' => $this->institutionA->id,
            'title' => 'Leçon avec forum',
        ]);
        $author = User::factory()->student()->create([
            'institution_id' => $this->institutionA->id,
            'name' => 'Awa Étudiante',
        ]);

        $open = ForumTopic::factory()->create([
            'institution_id' => $this->institutionA->id,
            'lesson_id' => $lesson->id,
            'user_id' => $author->id,
            'title' => 'Question ouverte',
        ]);
        // Résolu → exclu.
        ForumTopic::factory()->resolved()->create([
            'institution_id' => $this->institutionA->id,
            'lesson_id' => $lesson->id,
            'user_id' => $author->id,
        ]);
        // Fermé (status != open) → exclu.
        ForumTopic::factory()->closed()->create([
            'institution_id' => $this->institutionA->id,
            'lesson_id' => $lesson->id,
            'user_id' => $author->id,
        ]);

        $dashboard = $this->service->buildDashboard($this->teacher);

        $this->assertSame(1, $dashboard['forum']['unresolved_topics']);
        $list = $dashboard['forum']['unresolved_topics_list'];
        $this->assertCount(1, $list);
        $this->assertSame($open->id, $list[0]['topic_id']);
        $this->assertSame('Question ouverte', $list[0]['title']);
        $this->assertSame('Leçon avec forum', $list[0]['lesson_title']);
        $this->assertSame('Awa Étudiante', $list[0]['author']);
    }

    public function test_teacher_without_content_gets_zeroed_payload_with_full_structure(): void
    {
        $dashboard = $this->service->buildDashboard($this->teacher);

        $this->assertSame(
            ['lessons', 'students', 'quizzes', 'forum'],
            array_keys($dashboard)
        );
        $this->assertSame(0, $dashboard['lessons']['total']);
        $this->assertSame(0, $dashboard['lessons']['published']);
        $this->assertSame(0, $dashboard['lessons']['draft']);
        $this->assertCount(0, $dashboard['lessons']['top_lessons']);
        $this->assertSame(0, $dashboard['students']['active_last_7_days']);
        $this->assertSame(0, $dashboard['quizzes']['total']);
        $this->assertSame(0, $dashboard['quizzes']['published']);
        $this->assertSame(0, $dashboard['quizzes']['to_grade']);
        $this->assertCount(0, $dashboard['quizzes']['to_grade_list']);
        $this->assertSame(0, $dashboard['quizzes']['total_attempts']);
        $this->assertSame(0.0, $dashboard['quizzes']['average_score']);
        $this->assertSame(0, $dashboard['forum']['unresolved_topics']);
        $this->assertCount(0, $dashboard['forum']['unresolved_topics_list']);
    }

    public function test_quiz_block_uses_real_columns_and_attempt_statuses(): void
    {
        $published = Quiz::factory()->forTeacher($this->teacher)->create([
            'institution_id' => $this->institutionA->id,
            'status' => 'published',
            'title' => 'Quiz publié',
        ]);
        $draft = Quiz::factory()->forTeacher($this->teacher)->create([
            'institution_id' => $this->institutionA->id,
            'status' => 'draft',
        ]);
        $otherTeacher = User::factory()->teacher()->create([
            'institution_id' => $this->institutionA->id,
        ]);
        $otherQuiz = Quiz::factory()->forTeacher($otherTeacher)->create([
            'institution_id' => $this->institutionA->id,
            'status' => 'published',
        ]);

        QuizQuestion::factory()->shortAnswer()->create(['quiz_id' => $published->id]);
        QuizAttempt::factory()->forQuiz($published)->create([
            'status' => 'submitted',
            'submitted_at' => now()->subHour(),
            'score' => null,
            'graded_at' => null,
        ]);
        QuizAttempt::factory()->graded()->forQuiz($published)->create(['score' => 80]);
        QuizAttempt::factory()->graded()->forQuiz($draft)->create(['score' => 60]);
        QuizAttempt::factory()->graded()->forQuiz($otherQuiz)->create(['score' => 20]);

        $dashboard = $this->service->buildDashboard($this->teacher);

        $this->assertSame(2, $dashboard['quizzes']['total']);
        $this->assertSame(1, $dashboard['quizzes']['published']);
        $this->assertSame(1, $dashboard['quizzes']['to_grade']);
        $this->assertCount(1, $dashboard['quizzes']['to_grade_list']);
        $this->assertSame('Quiz publié', $dashboard['quizzes']['to_grade_list'][0]['quiz_title']);
        $this->assertSame(3, $dashboard['quizzes']['total_attempts']);
        $this->assertSame(70.0, $dashboard['quizzes']['average_score']);
    }

    public function test_tenant_a_dashboard_ignores_identical_teacher_data_from_tenant_b(): void
    {
        Lesson::factory()->forTeacher($this->teacher)->published()->create([
            'institution_id' => $this->institutionA->id,
        ]);
        // Même enseignant_id mais institution B : le scope global doit l'exclure.
        Lesson::factory()->published()->create([
            'institution_id' => $this->institutionB->id,
            'enseignant_id' => $this->teacher->id,
        ]);

        $dashboard = $this->service->buildDashboard($this->teacher);

        $this->assertSame(1, $dashboard['lessons']['total']);
        $this->assertSame(1, $dashboard['lessons']['published']);
    }

    public function test_tenant_b_dashboard_only_sees_tenant_b_lessons(): void
    {
        $teacherB = User::factory()->teacher()->create([
            'institution_id' => $this->institutionB->id,
        ]);
        Lesson::factory()->published()->create([
            'institution_id' => $this->institutionB->id,
            'enseignant_id' => $teacherB->id,
        ]);
        Lesson::factory()->draft()->create([
            'institution_id' => $this->institutionB->id,
            'enseignant_id' => $teacherB->id,
        ]);
        // Bruit côté A avec le même enseignant_id.
        Lesson::factory()->published()->create([
            'institution_id' => $this->institutionA->id,
            'enseignant_id' => $teacherB->id,
        ]);

        $this->app->make(TenantManager::class)->set($this->institutionB);

        $dashboard = $this->service->buildDashboard($teacherB);

        $this->assertSame(2, $dashboard['lessons']['total']);
        $this->assertSame(1, $dashboard['lessons']['published']);
        $this->assertSame(1, $dashboard['lessons']['draft']);
    }

    /**
     * §1.4 zéro N+1 : le nombre de requêtes SQL doit rester CONSTANT quand
     * le volume de données croît (agrégats `withCount`/`withAvg` + eager
     * loading, jamais une requête par ligne).
     */
    public function test_query_count_is_constant_when_data_volume_grows(): void
    {
        $this->createLessonWithProgressAndTopic();

        DB::enableQueryLog();
        $this->service->buildDashboard($this->teacher);
        $baseline = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 4 leçons supplémentaires, chacune avec progression + topic forum.
        for ($i = 0; $i < 4; $i++) {
            $this->createLessonWithProgressAndTopic();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->buildDashboard($this->teacher);
        $afterGrowth = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $baseline,
            $afterGrowth,
            "N+1 détecté : {$baseline} requêtes avec 1 leçon vs {$afterGrowth} avec 5 leçons."
        );
    }

    private function createLessonWithProgressAndTopic(): void
    {
        $lesson = Lesson::factory()->forTeacher($this->teacher)->published()->create([
            'institution_id' => $this->institutionA->id,
        ]);
        $student = User::factory()->student()->create([
            'institution_id' => $this->institutionA->id,
        ]);
        LessonProgress::factory()->inProgress()->create([
            'lesson_id' => $lesson->id,
            'user_id' => $student->id,
            'last_accessed_at' => now()->subDay(),
        ]);
        ForumTopic::factory()->create([
            'institution_id' => $this->institutionA->id,
            'lesson_id' => $lesson->id,
            'user_id' => $student->id,
        ]);
    }
}
