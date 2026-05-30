<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TEST-03 — `DashboardAdminController::stats` doit retourner les counts
 * agrégés UNIQUEMENT pour l'institution du coordinateur authentifié.
 *
 * Bug initial fixé : `forum.total_posts` utilisait `DB::table('forum_posts')->count()`
 * qui court-circuitait totalement le scope global `BelongsToInstitution` →
 * count des posts de TOUTES les institutions. Fix : utiliser `ForumPost::count()`.
 *
 * Les autres counts (Lesson, Quiz, ForumTopic, Notification) passent par
 * Eloquent → ils sont déjà couverts par `BelongsToInstitution` mais on les
 * teste explicitement en HTTP pour détecter toute future régression.
 *
 * ## Choix d'authentification
 *
 * Le test passe par un Bearer token (pas `Sanctum::actingAs`) afin que le
 * middleware `ResolveInstitution` s'exécute réellement et résolve le tenant
 * via le JWT. `Sanctum::actingAs` bypasserait le middleware → pas de tenant →
 * scope `BelongsToInstitution` skipped → tous les counts retournés (faux
 * positif sur cross-tenant isolation).
 *
 * @see app/Http/Controllers/API/Dashboard/DashboardAdminController.php::stats
 * @see app/Http/Middleware/ResolveInstitution.php
 * @see app/Models/Traits/BelongsToInstitution.php
 */
final class DashboardAdminStatsIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institutionA;
    private Institution $institutionB;
    private User $coordA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institutionA = Institution::factory()->create(['slug' => 'school-a']);
        $this->institutionB = Institution::factory()->create(['slug' => 'school-b']);

        $this->coordA = User::factory()->create([
            'institution_id' => $this->institutionA->id,
            'role' => 'coordinateur',
        ]);
    }

    /**
     * Bug initial fixé en TEST-03 : `DB::table('forum_posts')->count()` ne
     * passait pas par Eloquent et donc bypassait le scope tenant.
     * On crée 3 posts dans A, 5 dans B → A doit voir 3, jamais 8.
     */
    public function test_forum_posts_count_is_isolated_per_institution(): void
    {
        $this->createForumPostsForInstitution($this->institutionA, 3);
        $this->createForumPostsForInstitution($this->institutionB, 5);

        $response = $this->callDashboardStatsAs($this->coordA);

        $response->assertStatus(200);
        $this->assertSame(
            3,
            $response->json('data.forum.total_posts'),
            'forum.total_posts must reflect only the coordinator institution (school-a). '
            . 'If 8 appears, the DB::table() bypass is back — see DashboardAdminController::stats.'
        );
    }

    /**
     * Régression : `ForumTopic::count()` passe par Eloquent et le scope filtre par tenant.
     */
    public function test_forum_topics_count_is_isolated_per_institution(): void
    {
        ForumTopic::factory()->count(2)->create([
            'institution_id' => $this->institutionA->id,
            'user_id' => $this->coordA->id,
        ]);
        ForumTopic::factory()->count(4)->create([
            'institution_id' => $this->institutionB->id,
            'user_id' => User::factory()->for($this->institutionB)->create()->id,
        ]);

        $response = $this->callDashboardStatsAs($this->coordA);

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.forum.total_topics'));
    }

    /**
     * Régression : `Lesson::count()` passe par Eloquent et le scope filtre par tenant.
     */
    public function test_lessons_count_is_isolated_per_institution(): void
    {
        $teacherA = User::factory()->create(['institution_id' => $this->institutionA->id, 'role' => 'enseignant']);
        $teacherB = User::factory()->create(['institution_id' => $this->institutionB->id, 'role' => 'enseignant']);

        Lesson::factory()->count(2)->create([
            'institution_id' => $this->institutionA->id,
            'enseignant_id' => $teacherA->id,
        ]);
        Lesson::factory()->count(5)->create([
            'institution_id' => $this->institutionB->id,
            'enseignant_id' => $teacherB->id,
        ]);

        $response = $this->callDashboardStatsAs($this->coordA);

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.lessons.total'));
    }

    /**
     * Régression : `Quiz::count()` passe par Eloquent et le scope filtre par tenant.
     */
    public function test_quizzes_count_is_isolated_per_institution(): void
    {
        Quiz::factory()->count(3)->create([
            'institution_id' => $this->institutionA->id,
            'created_by' => $this->coordA->id,
        ]);
        Quiz::factory()->count(7)->create([
            'institution_id' => $this->institutionB->id,
            'created_by' => User::factory()->for($this->institutionB)->create()->id,
        ]);

        $response = $this->callDashboardStatsAs($this->coordA);

        $response->assertStatus(200);
        $this->assertSame(3, $response->json('data.quizzes.total'));
    }

    /**
     * Régression : `Notification::count()` et `whereNull('read_at')` passent
     * par Eloquent — leur count ne fuit pas cross-tenant.
     */
    public function test_notifications_count_is_isolated_per_institution(): void
    {
        Notification::factory()->count(2)->create([
            'institution_id' => $this->institutionA->id,
            'user_id' => $this->coordA->id,
            'read_at' => null,
        ]);
        Notification::factory()->count(6)->create([
            'institution_id' => $this->institutionB->id,
            'user_id' => User::factory()->for($this->institutionB)->create()->id,
            'read_at' => null,
        ]);

        $response = $this->callDashboardStatsAs($this->coordA);

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.notifications.total'));
        $this->assertSame(2, $response->json('data.notifications.unread'));
    }

    /**
     * Appelle l'endpoint /api/dashboard/stats via un vrai Bearer token afin
     * que `ResolveInstitution` middleware s'exécute et résolve le tenant.
     */
    private function callDashboardStatsAs(User $user): \Illuminate\Testing\TestResponse
    {
        $token = $user->createToken('dashboard-isolation-test')->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/dashboard/stats');
    }

    /**
     * Helper : crée `$count` ForumPost rattachés à un topic de l'institution.
     */
    private function createForumPostsForInstitution(Institution $institution, int $count): void
    {
        $author = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'etudiant',
        ]);

        $topic = ForumTopic::factory()->create([
            'institution_id' => $institution->id,
            'user_id' => $author->id,
        ]);

        ForumPost::factory()->count($count)->create([
            'institution_id' => $institution->id,
            'topic_id' => $topic->id,
            'user_id' => $author->id,
        ]);
    }
}
