<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;

/**
 * CRITICAL-06: BelongsToInstitution — Enforce tenant resolution
 *
 * Verify fail-secure behavior when TenantManager fails to resolve.
 * Tests that queries throw exceptions rather than returning cross-tenant data.
 */
class BelongsToInstitutionTest extends TestCase
{
    private Institution $institution1;
    private Institution $institution2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution1 = Institution::factory()->create(['slug' => 'inst1']);
        $this->institution2 = Institution::factory()->create(['slug' => 'inst2']);
    }

    /**
     * Test that TenantManager::getResolved() throws if not initialized
     */
    public function test_tenant_manager_getresolved_throws_if_not_initialized()
    {
        $tenantManager = app(TenantManager::class);
        $tenantManager->reset();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Tenant resolution failed/');

        $tenantManager->getResolved();
    }

    /**
     * Test that TenantManager::getResolvedSlug() throws if not initialized
     */
    public function test_tenant_manager_getresolvedslug_throws_if_not_initialized()
    {
        $tenantManager = app(TenantManager::class);
        $tenantManager->reset();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Tenant resolution failed/');

        $tenantManager->getResolvedSlug();
    }

    /**
     * Test that BelongsToInstitution throws when tenant not resolved
     */
    public function test_belongstoinstitution_throws_when_tenant_not_resolved()
    {
        User::factory()->create([
            'institution_id' => $this->institution1->id,
            'name' => 'User1'
        ]);
        User::factory()->create([
            'institution_id' => $this->institution2->id,
            'name' => 'User2'
        ]);

        $tenantManager = app(TenantManager::class);
        $tenantManager->reset();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Tenant resolution failed/');

        User::query()->get();
    }

    /**
     * Test that BelongsToInstitution filters correctly when tenant resolved
     */
    public function test_belongstoinstitution_filters_correctly_when_resolved()
    {
        User::factory()->create([
            'institution_id' => $this->institution1->id,
            'name' => 'User1',
            'email' => 'user1@inst1.test'
        ]);
        User::factory()->create([
            'institution_id' => $this->institution2->id,
            'name' => 'User2',
            'email' => 'user2@inst2.test'
        ]);

        $tenantManager = app(TenantManager::class);
        $tenantManager->set($this->institution1);

        $users = User::query()->get();

        $this->assertCount(1, $users);
        $this->assertEquals('User1', $users[0]->name);
        $this->assertEquals($this->institution1->id, $users[0]->institution_id);
    }

    /**
     * Test that creating model without institution_id uses resolved tenant
     */
    public function test_creating_model_uses_resolved_tenant()
    {
        $tenantManager = app(TenantManager::class);
        $tenantManager->set($this->institution1);

        // No institution_id provided → auto-set from resolved tenant.
        $user = User::factory()->create([
            'name' => 'NewUser',
        ]);

        $this->assertEquals($this->institution1->id, $user->institution_id);
    }

    /**
     * Test that creating model fails if tenant not resolved
     */
    public function test_creating_model_throws_if_tenant_not_resolved()
    {
        $tenantManager = app(TenantManager::class);
        $tenantManager->reset();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Tenant resolution failed/');

        // No institution_id, no tenant → must throw.
        User::factory()->create();
    }

    /**
     * Test that explicit institution_id => null (e.g. supradmin / system rows)
     * is preserved and NOT overridden by the tenant resolver.
     *
     * This is required for the supradmin seed flow where institution_id
     * is intentionally null (cross-tenant platform admin).
     */
    public function test_explicit_null_institution_id_is_preserved()
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
