<?php

declare(strict_types=1);

namespace Tests\Feature\Files;

use App\Models\File;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Integration tests for `GET /api/files/stats` tenant isolation (issue #102 fix).
 *
 * Non-supradmin callers see counts scoped to their institution (+ user-scoped
 * for students). Supradmin sees global counts.
 *
 * @see app/Http/Controllers/API/FileController.php::stats()
 * @see .claude/specs/file-idor-cross-tenant/design.md §3.3
 */
final class FileStatsAuthorizationTest extends TestCase
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

        Cache::flush();

        // 3 files in A, 2 in B
        $ownerA = $this->createUser($this->instA, 'etudiant');
        $ownerB = $this->createUser($this->instB, 'etudiant');

        for ($i = 0; $i < 3; $i++) {
            File::factory()->create([
                'user_id' => $ownerA->id,
                'institution_id' => $this->instA->id,
            ]);
        }
        for ($i = 0; $i < 2; $i++) {
            File::factory()->create([
                'user_id' => $ownerB->id,
                'institution_id' => $this->instB->id,
            ]);
        }
    }

    private function createUser(Institution $inst, string $role): User
    {
        return User::factory()->create([
            'institution_id' => $inst->id,
            'role' => $role,
        ]);
    }

    public function test_admin_sees_only_their_institution_stats(): void
    {
        $admin = $this->createUser($this->instA, 'admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/files/stats')
            ->assertStatus(200)
            ->assertJsonPath('data.total_files', 3);
    }

    public function test_student_sees_only_their_own_files(): void
    {
        $student = $this->createUser($this->instA, 'etudiant');
        // Add 1 file owned by this student
        File::factory()->create([
            'user_id' => $student->id,
            'institution_id' => $this->instA->id,
        ]);
        Sanctum::actingAs($student);

        $this->getJson('/api/files/stats')
            ->assertStatus(200)
            ->assertJsonPath('data.total_files', 1);
    }

    public function test_supradmin_sees_global_stats(): void
    {
        $supradmin = User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]);
        Sanctum::actingAs($supradmin);

        $this->getJson('/api/files/stats')
            ->assertStatus(200)
            ->assertJsonPath('data.total_files', 5);
    }

    public function test_admin_intra_A_does_not_see_intra_B_counts(): void
    {
        $adminA = $this->createUser($this->instA, 'admin');
        Sanctum::actingAs($adminA);
        $this->getJson('/api/files/stats')->assertJsonPath('data.total_files', 3);

        $adminB = $this->createUser($this->instB, 'admin');
        Sanctum::actingAs($adminB);
        $this->getJson('/api/files/stats')->assertJsonPath('data.total_files', 2);
    }
}
