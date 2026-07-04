<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dashboard;

use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Dashboard\AdminDashboardService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests unitaires de {@see AdminDashboardService} (issue #364 — TDD :
 * écrits AVANT l'extraction de la logique hors de
 * `DashboardAdminController::stats`).
 *
 * Deux mécanismes d'isolation se CUMULENT sur les users (verbatim du
 * controller) : le scope global `BelongsToInstitution` (User l'utilise
 * aussi) + le filtre manuel `klassci_tenant_url` (`when($tenantUrl, ...)`).
 * Les modèles de contenu (Lesson, Quiz, ForumTopic, ForumPost,
 * Notification, QuizAttempt) ne sont isolés que par le scope global.
 *
 * Couverture : happy path, edge (tenantUrl null → filtre URL désactivé,
 * scope institution seul), multi-tenant A/B (§1.3), activité récente 7 jours.
 */
final class AdminDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_URL_A = 'https://school-a.klassci.test';
    private const TENANT_URL_B = 'https://school-b.klassci.test';

    private AdminDashboardService $service;
    private Institution $institutionA;
    private Institution $institutionB;
    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AdminDashboardService();
        $this->institutionA = Institution::factory()->create(['slug' => 'school-a']);
        $this->institutionB = Institution::factory()->create(['slug' => 'school-b']);

        $this->coordinator = User::factory()->coordinator()->create([
            'institution_id' => $this->institutionA->id,
            'klassci_tenant_url' => self::TENANT_URL_A,
        ]);

        $this->app->make(TenantManager::class)->set($this->institutionA);
    }

    public function test_user_counts_are_filtered_by_the_coordinator_tenant_url(): void
    {
        User::factory()->student()->count(2)->create([
            'institution_id' => $this->institutionA->id,
            'klassci_tenant_url' => self::TENANT_URL_A,
        ]);
        User::factory()->teacher()->create([
            'institution_id' => $this->institutionA->id,
            'klassci_tenant_url' => self::TENANT_URL_A,
        ]);
        // Même institution mais autre tenant URL : exclu par le filtre URL
        // seul (prouve que le `when()` agit en plus du scope institution).
        User::factory()->student()->create([
            'institution_id' => $this->institutionA->id,
            'klassci_tenant_url' => self::TENANT_URL_B,
        ]);

        $stats = $this->service->buildStats($this->coordinator);

        // total = coordinateur + 2 étudiants + 1 enseignant (même tenant URL).
        $this->assertSame(4, $stats['users']['total']);
        $this->assertSame(2, $stats['users']['students']);
        $this->assertSame(1, $stats['users']['teachers']);
    }

    /**
     * CARACTÉRISATION (verbatim controller) : quand le coordinateur n'a pas
     * de `klassci_tenant_url`, `when(null, ...)` désactive le filtre URL —
     * il ne reste alors que le scope `BelongsToInstitution` : les users
     * d'autres URLs de la MÊME institution redeviennent visibles.
     * Comportement préservé tel quel — tout durcissement est un choix
     * produit hors périmètre #364.
     */
    public function test_url_filter_is_disabled_when_coordinator_has_no_tenant_url(): void
    {
        $coordinatorWithoutUrl = User::factory()->coordinator()->create([
            'institution_id' => $this->institutionA->id,
            'klassci_tenant_url' => null,
        ]);
        // Institution A mais URL B : visible UNIQUEMENT si le filtre URL
        // est désactivé.
        User::factory()->student()->create([
            'institution_id' => $this->institutionA->id,
            'klassci_tenant_url' => self::TENANT_URL_B,
        ]);
        // Institution B : toujours exclu par le scope global.
        User::factory()->student()->create([
            'institution_id' => $this->institutionB->id,
            'klassci_tenant_url' => self::TENANT_URL_B,
        ]);

        $stats = $this->service->buildStats($coordinatorWithoutUrl);

        // coordinator(setUp) + coordinatorWithoutUrl + étudiant A-URL-B = 3.
        $this->assertSame(3, $stats['users']['total']);
        $this->assertSame(1, $stats['users']['students']);
    }

    public function test_content_counts_reflect_only_tenant_a_scoped_models(): void
    {
        $teacherA = User::factory()->teacher()->create([
            'institution_id' => $this->institutionA->id,
        ]);
        Lesson::factory()->forTeacher($teacherA)->published()->create([
            'institution_id' => $this->institutionA->id,
        ]);
        Lesson::factory()->forTeacher($teacherA)->draft()->create([
            'institution_id' => $this->institutionA->id,
        ]);
        Quiz::factory()->forTeacher($teacherA)->create([
            'institution_id' => $this->institutionA->id,
        ]);

        $topicA = ForumTopic::factory()->create([
            'institution_id' => $this->institutionA->id,
            'user_id' => $teacherA->id,
        ]);
        ForumPost::factory()->count(3)->create([
            'institution_id' => $this->institutionA->id,
            'topic_id' => $topicA->id,
            'user_id' => $teacherA->id,
        ]);

        Notification::factory()->count(2)->create([
            'institution_id' => $this->institutionA->id,
            'user_id' => $teacherA->id,
            'read_at' => null,
        ]);
        Notification::factory()->create([
            'institution_id' => $this->institutionA->id,
            'user_id' => $teacherA->id,
            'read_at' => now(),
        ]);

        // Bruit côté institution B : jamais compté sous tenant A.
        $teacherB = User::factory()->teacher()->create([
            'institution_id' => $this->institutionB->id,
        ]);
        Lesson::factory()->forTeacher($teacherB)->published()->create([
            'institution_id' => $this->institutionB->id,
        ]);
        Quiz::factory()->forTeacher($teacherB)->create([
            'institution_id' => $this->institutionB->id,
        ]);
        ForumTopic::factory()->create([
            'institution_id' => $this->institutionB->id,
            'user_id' => $teacherB->id,
        ]);
        Notification::factory()->create([
            'institution_id' => $this->institutionB->id,
            'user_id' => $teacherB->id,
            'read_at' => null,
        ]);

        $stats = $this->service->buildStats($this->coordinator);

        $this->assertSame(2, $stats['lessons']['total']);
        $this->assertSame(1, $stats['lessons']['published']);
        $this->assertSame(1, $stats['quizzes']['total']);
        $this->assertSame(1, $stats['forum']['total_topics']);
        $this->assertSame(3, $stats['forum']['total_posts']);
        $this->assertSame(3, $stats['notifications']['total']);
        $this->assertSame(2, $stats['notifications']['unread']);
    }

    public function test_tenant_b_stats_only_see_tenant_b_content(): void
    {
        $teacherB = User::factory()->teacher()->create([
            'institution_id' => $this->institutionB->id,
        ]);
        Lesson::factory()->forTeacher($teacherB)->published()->count(2)->create([
            'institution_id' => $this->institutionB->id,
        ]);
        // Bruit côté A.
        $teacherA = User::factory()->teacher()->create([
            'institution_id' => $this->institutionA->id,
        ]);
        Lesson::factory()->forTeacher($teacherA)->published()->count(5)->create([
            'institution_id' => $this->institutionA->id,
        ]);

        $coordinatorB = User::factory()->coordinator()->create([
            'institution_id' => $this->institutionB->id,
            'klassci_tenant_url' => self::TENANT_URL_B,
        ]);
        $this->app->make(TenantManager::class)->set($this->institutionB);

        $stats = $this->service->buildStats($coordinatorB);

        $this->assertSame(2, $stats['lessons']['total']);
        // Users : seuls coordinatorB porte l'URL du tenant B.
        $this->assertSame(1, $stats['users']['total']);
    }

    public function test_recent_activity_counts_only_the_last_seven_days(): void
    {
        // Récents (comptés) : le coordinateur du setUp + 1 étudiant.
        User::factory()->student()->create([
            'institution_id' => $this->institutionA->id,
            'klassci_tenant_url' => self::TENANT_URL_A,
        ]);
        // Ancien (exclu) : créé il y a 30 jours.
        User::factory()->student()->create([
            'institution_id' => $this->institutionA->id,
            'klassci_tenant_url' => self::TENANT_URL_A,
            'created_at' => now()->subDays(30),
        ]);

        $teacherA = User::factory()->teacher()->create([
            'institution_id' => $this->institutionA->id,
        ]);
        Lesson::factory()->forTeacher($teacherA)->published()->create([
            'institution_id' => $this->institutionA->id,
        ]);
        Lesson::factory()->forTeacher($teacherA)->published()->create([
            'institution_id' => $this->institutionA->id,
            'created_at' => now()->subDays(30),
        ]);

        $quiz = Quiz::factory()->forTeacher($teacherA)->create([
            'institution_id' => $this->institutionA->id,
        ]);
        // `new_quiz_attempts` ne filtre PAS par statut (verbatim) : une
        // tentative récente compte quel que soit son statut.
        QuizAttempt::factory()->forQuiz($quiz)->create();
        QuizAttempt::factory()->forQuiz($quiz)->create([
            'created_at' => now()->subDays(30),
        ]);

        ForumTopic::factory()->create([
            'institution_id' => $this->institutionA->id,
            'user_id' => $teacherA->id,
        ]);
        ForumTopic::factory()->create([
            'institution_id' => $this->institutionA->id,
            'user_id' => $teacherA->id,
            'created_at' => now()->subDays(30),
        ]);

        $stats = $this->service->buildStats($this->coordinator);

        // coordinateur (setUp) + étudiant récent = 2 users récents avec URL A.
        // (teacherA n'a pas l'URL du tenant → exclu du filtre users.)
        $this->assertSame(2, $stats['recent_activity']['new_users']);
        $this->assertSame(1, $stats['recent_activity']['new_lessons']);
        $this->assertSame(1, $stats['recent_activity']['new_quiz_attempts']);
        $this->assertSame(1, $stats['recent_activity']['new_forum_topics']);
    }

    /**
     * CARACTÉRISATION : `quizzes.total_attempts` filtre `status='completed'`,
     * valeur inexistante dans la contrainte CHECK du schéma
     * (`in_progress|submitted|graded|abandoned`) → toujours 0 sur le schéma
     * courant. Préservé verbatim (voir PR #364 pour le finding).
     */
    public function test_completed_attempts_counter_stays_zero_under_current_schema(): void
    {
        $teacherA = User::factory()->teacher()->create([
            'institution_id' => $this->institutionA->id,
        ]);
        $quiz = Quiz::factory()->forTeacher($teacherA)->create([
            'institution_id' => $this->institutionA->id,
        ]);
        QuizAttempt::factory()->graded()->forQuiz($quiz)->create();

        $stats = $this->service->buildStats($this->coordinator);

        $this->assertSame(0, $stats['quizzes']['total_attempts']);
    }

    public function test_payload_structure_matches_the_locked_response_contract(): void
    {
        $stats = $this->service->buildStats($this->coordinator);

        $this->assertSame(
            ['users', 'lessons', 'quizzes', 'forum', 'notifications', 'recent_activity'],
            array_keys($stats)
        );
        $this->assertSame(['total', 'students', 'teachers'], array_keys($stats['users']));
        $this->assertSame(['total', 'published'], array_keys($stats['lessons']));
        $this->assertSame(['total', 'total_attempts'], array_keys($stats['quizzes']));
        $this->assertSame(['total_topics', 'total_posts'], array_keys($stats['forum']));
        $this->assertSame(['total', 'unread'], array_keys($stats['notifications']));
        $this->assertSame(
            ['new_users', 'new_lessons', 'new_quiz_attempts', 'new_forum_topics'],
            array_keys($stats['recent_activity'])
        );
    }
}
