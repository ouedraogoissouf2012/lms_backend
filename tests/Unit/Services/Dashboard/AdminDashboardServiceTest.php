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
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Tests unitaires de {@see AdminDashboardService} (issue #364 — TDD :
 * écrits AVANT l'extraction de la logique hors de
 * `DashboardAdminController::stats`. Étendu #546 — cache 300s scopé tenant).
 *
 * Deux mécanismes d'isolation se CUMULENT sur les users (verbatim du
 * controller) : le scope global `BelongsToInstitution` (User l'utilise
 * aussi) + le filtre manuel `klassci_tenant_url` (`when($tenantUrl, ...)`).
 * Les modèles de contenu (Lesson, Quiz, ForumTopic, ForumPost,
 * Notification, QuizAttempt) ne sont isolés que par le scope global.
 *
 * Couverture : happy path, edge (tenantUrl null → filtre URL désactivé,
 * scope institution seul), multi-tenant A/B (§1.3), activité récente 7 jours,
 * cache 300s (hit, isolation inter-institution, isolation intra-institution
 * par `klassci_tenant_url` — #546).
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

        // Sanity : store `database` (phpunit.xml) partagé entre tests — clean
        // state garanti même si RefreshDatabase ne couvre pas la table `cache`
        // (cf. pattern AdminAnalyticsCacheIsolationTest).
        Cache::flush();

        $this->service = $this->app->make(AdminDashboardService::class);
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

    /**
     * #546 REQ-1 — deuxième appel dans la fenêtre TTL(300s) servi depuis le
     * cache : une leçon créée ENTRE les deux appels ne doit PAS apparaître
     * dans le second (preuve comportementale, pas juste un count de requêtes).
     */
    public function test_second_call_within_ttl_is_served_from_cache(): void
    {
        $teacherA = User::factory()->teacher()->create(['institution_id' => $this->institutionA->id]);
        Lesson::factory()->forTeacher($teacherA)->published()->create([
            'institution_id' => $this->institutionA->id,
        ]);

        $first = $this->service->buildStats($this->coordinator);
        $this->assertSame(1, $first['lessons']['total']);

        // Écriture après le 1er appel : un buildStats() non caché la verrait.
        Lesson::factory()->forTeacher($teacherA)->published()->create([
            'institution_id' => $this->institutionA->id,
        ]);

        $second = $this->service->buildStats($this->coordinator);
        $this->assertSame(
            1,
            $second['lessons']['total'],
            'Le 2e appel doit être servi depuis le cache 300s, pas re-agrégé.'
        );
    }

    /**
     * #546 REQ-1 — la clé de cache est scopée par le slug d'institution
     * résolu (jamais un identifiant dérivé de l'URL KLASSCI seule) :
     * deux institutions n'écrasent jamais le cache l'une de l'autre.
     */
    public function test_cache_is_isolated_between_institutions(): void
    {
        $teacherA = User::factory()->teacher()->create(['institution_id' => $this->institutionA->id]);
        Lesson::factory()->forTeacher($teacherA)->published()->count(3)->create([
            'institution_id' => $this->institutionA->id,
        ]);
        $statsA = $this->service->buildStats($this->coordinator);

        $teacherB = User::factory()->teacher()->create(['institution_id' => $this->institutionB->id]);
        Lesson::factory()->forTeacher($teacherB)->published()->create([
            'institution_id' => $this->institutionB->id,
        ]);
        $coordinatorB = User::factory()->coordinator()->create([
            'institution_id' => $this->institutionB->id,
            'klassci_tenant_url' => self::TENANT_URL_B,
        ]);
        $this->app->make(TenantManager::class)->set($this->institutionB);
        $statsB = $this->service->buildStats($coordinatorB);

        $this->assertSame(3, $statsA['lessons']['total']);
        $this->assertSame(
            1,
            $statsB['lessons']['total'],
            'Institution B ne doit jamais voir le cache agrégé de A (fuite cross-tenant).'
        );
    }

    /**
     * #546 REQ-2 — au sein d'une MÊME institution, deux coordinateurs de
     * `klassci_tenant_url` différents ne doivent PAS partager le cache : la
     * clé doit inclure ce sous-scope, sinon `test_url_filter_is_disabled_*`
     * casserait silencieusement sous cache.
     */
    public function test_cache_is_isolated_between_coordinators_with_different_tenant_url(): void
    {
        User::factory()->student()->count(2)->create([
            'institution_id' => $this->institutionA->id,
            'klassci_tenant_url' => self::TENANT_URL_A,
        ]);
        $statsUrlA = $this->service->buildStats($this->coordinator);
        // coordinateur (URL A) + 2 étudiants (URL A) = 3.
        $this->assertSame(3, $statsUrlA['users']['total']);

        $coordinatorWithoutUrl = User::factory()->coordinator()->create([
            'institution_id' => $this->institutionA->id,
            'klassci_tenant_url' => null,
        ]);
        $statsNoUrl = $this->service->buildStats($coordinatorWithoutUrl);

        // Sans filtre URL (when(null,...) désactivé) : TOUS les users de
        // l'institution A sont visibles — coordinateur(3) + coordinatorWithoutUrl(1) = 4.
        // Si le cache fuitait entre les deux scopes, on retrouverait 3 (valeur de l'appel URL A).
        $this->assertSame(
            4,
            $statsNoUrl['users']['total'],
            'Le coordinateur sans tenant_url ne doit pas hériter du cache du coordinateur avec URL A.'
        );
    }
}
