<?php

declare(strict_types=1);

namespace Tests\Feature\Files;

use App\Models\File;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Integration tests for `PUT /api/files/{file}` and `DELETE /api/files/{file}`
 * authorization (issue #102 fix).
 *
 * Update and Delete both use `ChecksFileAuthorization::canModerateFile()`.
 * is_public does NOT grant moderation rights (read-only).
 *
 * @see app/Http/Requests/UpdateFileRequest.php
 * @see app/Http/Requests/DeleteFileRequest.php
 * @see .claude/specs/file-idor-cross-tenant/design.md §4.2
 */
final class FileModerateAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $instA;
    private Institution $instB;

    protected function setUp(): void
    {
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

    private function createFile(Institution $inst, User $owner, bool $isPublic = false): File
    {
        return File::factory()->create([
            'user_id' => $owner->id,
            'institution_id' => $inst->id,
            'is_public' => $isPublic,
        ]);
    }

    public function test_owner_can_update_intra_tenant(): void
    {
        $owner = $this->createUser($this->instA, 'etudiant');
        $file = $this->createFile($this->instA, $owner);
        Sanctum::actingAs($owner);

        $this->putJson("/api/files/{$file->id}", ['description' => 'updated'])
            ->assertStatus(200);
    }

    public function test_admin_can_update_intra_tenant(): void
    {
        $owner = $this->createUser($this->instA, 'etudiant');
        $file = $this->createFile($this->instA, $owner);
        $admin = $this->createUser($this->instA, 'admin');
        Sanctum::actingAs($admin);

        $this->putJson("/api/files/{$file->id}", ['description' => 'admin edit'])
            ->assertStatus(200);
    }

    public function test_student_cannot_update_public_file_intra_tenant(): void
    {
        // is_public does NOT grant moderation rights
        $owner = $this->createUser($this->instA, 'etudiant');
        $file = $this->createFile($this->instA, $owner, isPublic: true);
        $otherStudent = $this->createUser($this->instA, 'etudiant');
        Sanctum::actingAs($otherStudent);

        $this->putJson("/api/files/{$file->id}", ['description' => 'should fail'])
            ->assertStatus(403);
    }

    public function test_admin_cannot_update_cross_tenant(): void
    {
        $owner = $this->createUser($this->instA, 'etudiant');
        $file = $this->createFile($this->instA, $owner);
        $adminB = $this->createUser($this->instB, 'admin');
        Sanctum::actingAs($adminB);

        self::assertContains(
            $this->putJson("/api/files/{$file->id}", ['description' => 'hack'])->status(),
            [403, 404],
        );
    }

    public function test_supradmin_can_update_cross_tenant(): void
    {
        $owner = $this->createUser($this->instA, 'etudiant');
        $file = $this->createFile($this->instA, $owner);
        $supradmin = User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]);
        Sanctum::actingAs($supradmin);

        $this->putJson("/api/files/{$file->id}", ['description' => 'supradmin'])
            ->assertStatus(200);
    }

    public function test_admin_cannot_delete_cross_tenant(): void
    {
        $owner = $this->createUser($this->instA, 'etudiant');
        $file = $this->createFile($this->instA, $owner);
        $adminB = $this->createUser($this->instB, 'admin');
        Sanctum::actingAs($adminB);

        self::assertContains(
            $this->deleteJson("/api/files/{$file->id}")->status(),
            [403, 404],
        );
    }
}
