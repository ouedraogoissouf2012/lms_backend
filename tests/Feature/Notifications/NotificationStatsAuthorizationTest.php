<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Integration tests for `GET /api/admin/notifications/stats` tenant isolation.
 *
 * Verifies that non-`supradmin` callers see counts scoped to their own institution,
 * `supradmin` sees the global counts, and the cache keys are tenant-namespaced so
 * a coordinator from institution A can never observe institution B's counts.
 *
 * @see app/Http/Controllers/API/NotificationsController.php::stats()
 * @see .claude/specs/notifications-cross-tenant/design.md §2.2
 */
final class NotificationStatsAuthorizationTest extends TestCase
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

        // Seed: 3 notifications in A, 2 in B
        $userA = $this->createUser($this->instA, 'etudiant');
        $userB = $this->createUser($this->instB, 'etudiant');

        for ($i = 0; $i < 3; $i++) {
            DB::table('notifications')->insert([
                'user_id' => $userA->id,
                'institution_id' => $this->instA->id,
                'type' => 'info',
                'title' => "A {$i}",
                'message' => 'test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        for ($i = 0; $i < 2; $i++) {
            DB::table('notifications')->insert([
                'user_id' => $userB->id,
                'institution_id' => $this->instB->id,
                'type' => 'info',
                'title' => "B {$i}",
                'message' => 'test',
                'created_at' => now(),
                'updated_at' => now(),
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

    public function test_coordinateur_sees_only_their_institution_stats(): void
    {
        $coordA = $this->createUser($this->instA, 'coordinateur');
        Sanctum::actingAs($coordA);

        $response = $this->getJson('/api/admin/notifications/stats');

        $response->assertStatus(200);
        $response->assertJsonPath('data.total', 3);
    }

    public function test_superAdmin_sees_only_their_institution_stats(): void
    {
        $adminA = $this->createUser($this->instA, 'superAdmin');
        Sanctum::actingAs($adminA);

        $response = $this->getJson('/api/admin/notifications/stats');

        $response->assertStatus(200);
        $response->assertJsonPath('data.total', 3);
    }

    public function test_supradmin_sees_global_stats_cross_tenant(): void
    {
        $supradmin = User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]);
        Sanctum::actingAs($supradmin);

        $response = $this->getJson('/api/admin/notifications/stats');

        $response->assertStatus(200);
        $response->assertJsonPath('data.total', 5);
    }

    public function test_cache_isolation_between_institutions(): void
    {
        // Coord A first — populates cache for institution A
        $coordA = $this->createUser($this->instA, 'coordinateur');
        Sanctum::actingAs($coordA);
        $responseA = $this->getJson('/api/admin/notifications/stats');
        $responseA->assertJsonPath('data.total', 3);

        // Coord B second — must NOT see A's cached value
        $coordB = $this->createUser($this->instB, 'coordinateur');
        Sanctum::actingAs($coordB);
        $responseB = $this->getJson('/api/admin/notifications/stats');
        $responseB->assertJsonPath('data.total', 2);
    }
}
