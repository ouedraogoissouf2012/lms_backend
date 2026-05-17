<?php

declare(strict_types=1);

namespace Tests\Feature\Forum;

use App\Models\ForumTopic;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Integration tests for `POST /api/forum/topics/{topic}/close` authorization.
 *
 * Verifies the matrix tenant × role × ownership through `CloseTopicRequest`.
 * This endpoint also requires `role:enseignant,coordinateur,admin` middleware,
 * so students are filtered upstream (401/403 from middleware, not from authorize).
 *
 * Reference: #91 — Forum IDOR cross-tenant fix (CloseTopic was the HIGH).
 *
 * @see app/Http/Requests/CloseTopicRequest.php
 * @see app/Http/Requests/Concerns/ChecksForumAuthorization.php
 * @see .claude/specs/forum-idor-cross-tenant/design.md §3.2
 */
final class CloseTopicAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $instA;
    private Institution $instB;

    protected function setUp(): void
    {
        // Skip BEFORE parent::setUp() — RefreshDatabase trait would otherwise
        // try to migrate and crash on missing pgsql driver.
        if (!extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('PostgreSQL PDO driver not available (CI-only test).');
        }

        parent::setUp();

        $this->instA = Institution::factory()->create(['slug' => 'school-a']);
        $this->instB = Institution::factory()->create(['slug' => 'school-b']);
    }

    private function createUser(Institution $inst, string $role): User
    {
        return User::factory()->create([
            'institution_id' => $inst->id,
            'role' => $role,
        ]);
    }

    private function createTopic(Institution $inst, User $author): ForumTopic
    {
        return ForumTopic::factory()->create([
            'user_id' => $author->id,
            'institution_id' => $inst->id,
        ]);
    }

    public function test_author_who_is_teacher_can_close_own_topic_intra_tenant(): void
    {
        $author = $this->createUser($this->instA, 'enseignant');
        $topic = $this->createTopic($this->instA, $author);

        Sanctum::actingAs($author);

        $response = $this->postJson("/api/forum/topics/{$topic->id}/close");

        $response->assertStatus(200);
    }

    public function test_admin_can_close_any_topic_intra_tenant(): void
    {
        $author = $this->createUser($this->instA, 'enseignant');
        $topic = $this->createTopic($this->instA, $author);

        $admin = $this->createUser($this->instA, 'admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/forum/topics/{$topic->id}/close");

        $response->assertStatus(200);
    }

    public function test_coordinateur_can_close_topic_intra_tenant(): void
    {
        $author = $this->createUser($this->instA, 'enseignant');
        $topic = $this->createTopic($this->instA, $author);

        $coordinateur = $this->createUser($this->instA, 'coordinateur');
        Sanctum::actingAs($coordinateur);

        $response = $this->postJson("/api/forum/topics/{$topic->id}/close");

        $response->assertStatus(200);
    }

    public function test_admin_cannot_close_topic_cross_tenant(): void
    {
        $author = $this->createUser($this->instA, 'enseignant');
        $topic = $this->createTopic($this->instA, $author);

        $adminB = $this->createUser($this->instB, 'admin');
        Sanctum::actingAs($adminB);

        $response = $this->postJson("/api/forum/topics/{$topic->id}/close");

        // Either 404 (scope) or 403 (authorize() denies).
        self::assertContains($response->status(), [403, 404]);
    }

    public function test_coordinateur_cannot_close_topic_cross_tenant(): void
    {
        $author = $this->createUser($this->instA, 'enseignant');
        $topic = $this->createTopic($this->instA, $author);

        $coordinateurB = $this->createUser($this->instB, 'coordinateur');
        Sanctum::actingAs($coordinateurB);

        $response = $this->postJson("/api/forum/topics/{$topic->id}/close");

        self::assertContains($response->status(), [403, 404]);
    }

    public function test_student_cannot_close_topic_blocked_by_role_middleware(): void
    {
        $author = $this->createUser($this->instA, 'enseignant');
        $topic = $this->createTopic($this->instA, $author);

        $student = $this->createUser($this->instA, 'etudiant');
        Sanctum::actingAs($student);

        $response = $this->postJson("/api/forum/topics/{$topic->id}/close");

        // The `role:enseignant,coordinateur,admin` middleware filters this
        // before reaching CloseTopicRequest::authorize().
        $response->assertStatus(403);
    }

    public function test_supradmin_can_close_topic_cross_tenant(): void
    {
        $author = $this->createUser($this->instA, 'enseignant');
        $topic = $this->createTopic($this->instA, $author);

        $supradmin = User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]);
        Sanctum::actingAs($supradmin);

        $response = $this->postJson("/api/forum/topics/{$topic->id}/close");

        $response->assertStatus(200);
    }
}
