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
 * Integration tests for `GET /api/files/{id}` and `GET /api/files/{id}/download`
 * authorization (issue #102 fix).
 *
 * Both endpoints use the same `ChecksFileAuthorization::canReadFile()` helper.
 * Public files are accessible intra-tenant; non-public files require ownership
 * or admin role; cross-tenant access is rejected for non-supradmin.
 *
 * @see app/Http/Controllers/API/FileController.php
 * @see app/Http/Requests/Concerns/ChecksFileAuthorization.php
 * @see .claude/specs/file-idor-cross-tenant/design.md §4.2
 */
final class FileReadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $instA;
    private Institution $instB;

    protected function setUp(): void
    {

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

    public function test_owner_can_read_intra_tenant(): void
    {
        $owner = $this->createUser($this->instA, 'etudiant');
        $file = $this->createFile($this->instA, $owner);
        Sanctum::actingAs($owner);

        $this->getJson("/api/files/{$file->id}")->assertStatus(200);
    }

    public function test_admin_can_read_intra_tenant(): void
    {
        $owner = $this->createUser($this->instA, 'etudiant');
        $file = $this->createFile($this->instA, $owner);
        $admin = $this->createUser($this->instA, 'admin');
        Sanctum::actingAs($admin);

        $this->getJson("/api/files/{$file->id}")->assertStatus(200);
    }

    public function test_student_can_read_public_file_intra_tenant(): void
    {
        $owner = $this->createUser($this->instA, 'etudiant');
        $file = $this->createFile($this->instA, $owner, isPublic: true);
        $otherStudent = $this->createUser($this->instA, 'etudiant');
        Sanctum::actingAs($otherStudent);

        $this->getJson("/api/files/{$file->id}")->assertStatus(200);
    }

    public function test_student_cannot_read_non_public_file_intra_tenant(): void
    {
        $owner = $this->createUser($this->instA, 'etudiant');
        $file = $this->createFile($this->instA, $owner, isPublic: false);
        $otherStudent = $this->createUser($this->instA, 'etudiant');
        Sanctum::actingAs($otherStudent);

        $this->getJson("/api/files/{$file->id}")->assertStatus(403);
    }

    public function test_admin_cannot_read_cross_tenant(): void
    {
        $owner = $this->createUser($this->instA, 'etudiant');
        $file = $this->createFile($this->instA, $owner);
        $adminB = $this->createUser($this->instB, 'admin');
        Sanctum::actingAs($adminB);

        self::assertContains(
            $this->getJson("/api/files/{$file->id}")->status(),
            [403, 404],
        );
    }

    public function test_supradmin_can_read_cross_tenant(): void
    {
        $owner = $this->createUser($this->instA, 'etudiant');
        $file = $this->createFile($this->instA, $owner);
        $supradmin = User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]);
        Sanctum::actingAs($supradmin);

        $this->getJson("/api/files/{$file->id}")->assertStatus(200);
    }

    public function test_download_admin_cannot_cross_tenant(): void
    {
        $owner = $this->createUser($this->instA, 'etudiant');
        $file = $this->createFile($this->instA, $owner);
        $adminB = $this->createUser($this->instB, 'admin');
        Sanctum::actingAs($adminB);

        self::assertContains(
            $this->getJson("/api/files/{$file->id}/download")->status(),
            [403, 404],
        );
    }
}
