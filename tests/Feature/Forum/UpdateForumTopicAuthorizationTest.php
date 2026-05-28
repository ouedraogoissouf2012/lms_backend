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
 * Integration tests for `PUT /api/forum/topics/{topic}` authorization.
 *
 * Verifies the matrix tenant × role × ownership through `UpdateForumTopicRequest`
 * combined with the `BelongsToInstitution` global scope. Defense-in-depth :
 * cross-tenant access is blocked by the scope (→ 404 from route binding) AND
 * by the FormRequest's `authorize()` (→ 403) if the scope were bypassed.
 *
 * Reference: #91 — Forum IDOR cross-tenant fix
 *
 * @see app/Http/Requests/UpdateForumTopicRequest.php
 * @see app/Http/Requests/Concerns/ChecksForumAuthorization.php
 * @see .claude/specs/forum-idor-cross-tenant/design.md §3.2
 */
final class UpdateForumTopicAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $instA;
    private Institution $instB;

    protected function setUp(): void
    {
        // Skip BEFORE parent::setUp() — RefreshDatabase trait would otherwise

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

    public function test_author_can_update_own_topic_intra_tenant(): void
    {
        $author = $this->createUser($this->instA, 'etudiant');
        $topic = $this->createTopic($this->instA, $author);

        Sanctum::actingAs($author);

        $response = $this->putJson("/api/forum/topics/{$topic->id}", [
            'title' => 'Updated title',
        ]);

        $response->assertStatus(200);
    }

    public function test_admin_can_update_any_topic_intra_tenant(): void
    {
        $author = $this->createUser($this->instA, 'etudiant');
        $topic = $this->createTopic($this->instA, $author);

        $admin = $this->createUser($this->instA, 'admin');
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/forum/topics/{$topic->id}", [
            'title' => 'Admin edited',
        ]);

        $response->assertStatus(200);
    }

    public function test_admin_cannot_update_topic_cross_tenant(): void
    {
        $author = $this->createUser($this->instA, 'etudiant');
        $topic = $this->createTopic($this->instA, $author);

        $adminB = $this->createUser($this->instB, 'admin');
        Sanctum::actingAs($adminB);

        $response = $this->putJson("/api/forum/topics/{$topic->id}", [
            'title' => 'Cross-tenant hack attempt',
        ]);

        // Either 404 (scope filters route binding) or 403 (authorize() denies).
        // Both are acceptable — both are non-200.
        self::assertContains($response->status(), [403, 404]);
    }

    public function test_non_author_student_cannot_update_topic_intra_tenant(): void
    {
        $author = $this->createUser($this->instA, 'etudiant');
        $topic = $this->createTopic($this->instA, $author);

        $otherStudent = $this->createUser($this->instA, 'etudiant');
        Sanctum::actingAs($otherStudent);

        $response = $this->putJson("/api/forum/topics/{$topic->id}", [
            'title' => 'Should fail',
        ]);

        $response->assertStatus(403);
    }

    public function test_coordinateur_cannot_update_topic_with_default_moderator_roles(): void
    {
        $author = $this->createUser($this->instA, 'etudiant');
        $topic = $this->createTopic($this->instA, $author);

        $coordinateur = $this->createUser($this->instA, 'coordinateur');
        Sanctum::actingAs($coordinateur);

        $response = $this->putJson("/api/forum/topics/{$topic->id}", [
            'title' => 'Coord cannot edit',
        ]);

        $response->assertStatus(403);
    }

    public function test_supradmin_can_update_topic_cross_tenant(): void
    {
        $author = $this->createUser($this->instA, 'etudiant');
        $topic = $this->createTopic($this->instA, $author);

        $supradmin = User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]);
        Sanctum::actingAs($supradmin);

        $response = $this->putJson("/api/forum/topics/{$topic->id}", [
            'title' => 'Supradmin override',
        ]);

        $response->assertStatus(200);
    }
}
