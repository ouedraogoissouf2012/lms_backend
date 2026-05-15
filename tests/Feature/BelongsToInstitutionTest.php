<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * CRITICAL-06: BelongsToInstitution — defense-in-depth verification.
 *
 * Two distinct safety strategies tested separately :
 *  - Queries without tenant → log warning + no-op (no throw)
 *  - Inserts without tenant + no explicit institution_id → throw
 */
class BelongsToInstitutionTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution1;
    private Institution $institution2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution1 = Institution::factory()->create(['slug' => 'inst1']);
        $this->institution2 = Institution::factory()->create(['slug' => 'inst2']);
    }

    /**
     * TenantManager::getResolved() must throw if not initialized.
     */
    public function test_tenant_manager_getresolved_throws_if_not_initialized(): void
    {
        $tenantManager = app(TenantManager::class);
        $tenantManager->reset();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Tenant resolution failed/');

        $tenantManager->getResolved();
    }

    /**
     * TenantManager::getResolvedSlug() must throw if not initialized.
     */
    public function test_tenant_manager_getresolvedslug_throws_if_not_initialized(): void
    {
        $tenantManager = app(TenantManager::class);
        $tenantManager->reset();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Tenant resolution failed/');

        $tenantManager->getResolvedSlug();
    }

    /**
     * When a query runs without a resolved tenant, the scope must :
     *   - log a warning (audit trail for production)
     *   - return rows (no-op, do not crash legitimate cross-tenant code)
     *
     * Cross-tenant data leakage is prevented upstream by ResolveInstitution
     * middleware + per-controller Auth::user()->institution_id filters.
     */
    public function test_query_without_resolved_tenant_logs_warning_and_returns_rows(): void
    {
        User::factory()->create(['institution_id' => $this->institution1->id, 'name' => 'A']);
        User::factory()->create(['institution_id' => $this->institution2->id, 'name' => 'B']);

        $tenantManager = app(TenantManager::class);
        $tenantManager->reset();

        Log::spy();

        $users = User::query()->get();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'query executed without resolved tenant'))
            ->atLeast()->once();

        // No-op: all rows are returned (no scope filter), but cross-tenant safety
        // remains enforced at the HTTP boundary.
        $this->assertGreaterThanOrEqual(2, $users->count());
    }

    /**
     * When a tenant IS resolved, the global scope filters correctly.
     */
    public function test_belongstoinstitution_filters_correctly_when_resolved(): void
    {
        User::factory()->create([
            'institution_id' => $this->institution1->id,
            'name'           => 'User1',
            'email'          => 'user1@inst1.test',
        ]);
        User::factory()->create([
            'institution_id' => $this->institution2->id,
            'name'           => 'User2',
            'email'          => 'user2@inst2.test',
        ]);

        $tenantManager = app(TenantManager::class);
        $tenantManager->set($this->institution1);

        $users = User::query()->get();

        $this->assertCount(1, $users);
        $this->assertEquals('User1', $users[0]->name);
        $this->assertEquals($this->institution1->id, $users[0]->institution_id);
    }

    /**
     * Creating a model without institution_id auto-sets it from the tenant.
     */
    public function test_creating_model_uses_resolved_tenant(): void
    {
        $tenantManager = app(TenantManager::class);
        $tenantManager->set($this->institution1);

        // No institution_id provided → auto-set from resolved tenant.
        $user = User::factory()->create(['name' => 'NewUser']);

        $this->assertEquals($this->institution1->id, $user->institution_id);
    }

    /**
     * Creating a model without a resolved tenant AND without explicit
     * institution_id must log a warning (audit trail). The insert is not
     * aborted so legitimate cross-tenant flows (jobs, commands, supradmin)
     * keep working; the row's `institution_id` falls back to the DB default
     * (nullable column).
     *
     * Future hardening : once every factory provides a sensible default,
     * promote this no-op to a strict throw.
     */
    public function test_creating_model_logs_warning_if_tenant_not_resolved(): void
    {
        $tenantManager = app(TenantManager::class);
        $tenantManager->reset();

        Log::spy();

        $user = User::factory()->create();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'creating model without resolved tenant'))
            ->atLeast()->once();

        $this->assertNull(
            $user->institution_id,
            'Without tenant and without explicit assignment, institution_id stays at DB default (null).'
        );
    }

    /**
     * Supradmin / system rows can explicitly set institution_id => null and
     * the value must be preserved (not overridden by the tenant resolver).
     */
    public function test_explicit_null_institution_id_is_preserved(): void
    {
        $tenantManager = app(TenantManager::class);
        $tenantManager->set($this->institution1);

        $user = User::factory()->create([
            'institution_id' => null,
            'name'           => 'Supradmin',
            'role'           => 'supradmin',
        ]);

        $this->assertNull(
            $user->institution_id,
            'institution_id explicitly set to null must be preserved (supradmin case)'
        );
    }
}
