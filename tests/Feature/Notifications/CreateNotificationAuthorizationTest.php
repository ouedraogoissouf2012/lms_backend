<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Integration tests for `POST /api/admin/notifications/create` authorization.
 *
 * Verifies the tenant isolation introduced by `CreateNotificationRequest::authorize()`
 * (issue #98). Coordinators and superAdmins are strictly confined to their own
 * institution. Supradmin (platform manager) bypasses tenant isolation by design.
 *
 * @see app/Http/Requests/CreateNotificationRequest.php
 * @see .claude/specs/notifications-cross-tenant/design.md §2.1
 */
final class CreateNotificationAuthorizationTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function payload(int $targetUserId): array
    {
        return [
            'user_id' => $targetUserId,
            'title' => 'Test notification',
            'message' => 'This is a test notification.',
            'type' => 'info',
        ];
    }

    public function test_coordinateur_can_create_intra_tenant(): void
    {
        $coord = $this->createUser($this->instA, 'coordinateur');
        $target = $this->createUser($this->instA, 'etudiant');

        Sanctum::actingAs($coord);

        $response = $this->postJson('/api/admin/notifications/create', $this->payload($target->id));

        $response->assertStatus(200);
    }

    public function test_coordinateur_cannot_create_cross_tenant(): void
    {
        $coord = $this->createUser($this->instA, 'coordinateur');
        $targetCrossTenant = $this->createUser($this->instB, 'etudiant');

        Sanctum::actingAs($coord);

        $response = $this->postJson('/api/admin/notifications/create', $this->payload($targetCrossTenant->id));

        $response->assertStatus(403);
    }

    public function test_superAdmin_can_create_intra_tenant(): void
    {
        $admin = $this->createUser($this->instA, 'superAdmin');
        $target = $this->createUser($this->instA, 'etudiant');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/notifications/create', $this->payload($target->id));

        $response->assertStatus(200);
    }

    public function test_superAdmin_cannot_create_cross_tenant(): void
    {
        $admin = $this->createUser($this->instA, 'superAdmin');
        $targetCrossTenant = $this->createUser($this->instB, 'etudiant');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/notifications/create', $this->payload($targetCrossTenant->id));

        $response->assertStatus(403);
    }

    public function test_supradmin_can_create_cross_tenant(): void
    {
        $supradmin = User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]);
        $target = $this->createUser($this->instA, 'etudiant');

        Sanctum::actingAs($supradmin);

        $response = $this->postJson('/api/admin/notifications/create', $this->payload($target->id));

        $response->assertStatus(200);
    }
}
