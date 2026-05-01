<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\TenantManager;
use App\Models\Institution;

/**
 * CRITICAL-06: TenantManager — Enforce fail-secure tenant resolution
 */
class TenantManagerTest extends TestCase
{
    /**
     * Test that getResolved() throws if tenant not set
     */
    public function test_getresolved_throws_if_tenant_not_set()
    {
        $tenantManager = new TenantManager();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Tenant resolution failed/');

        $tenantManager->getResolved();
    }

    /**
     * Test that getResolved() returns ID if tenant is set
     */
    public function test_getresolved_returns_id_when_set()
    {
        $tenantManager = new TenantManager();

        $institution = \Mockery::mock(Institution::class)
            ->shouldIgnoreMissing()
            ->makePartial();
        $institution->id = 123;

        $tenantManager->set($institution);

        $this->assertEquals(123, $tenantManager->getResolved());
    }

    /**
     * Test that getResolvedSlug() throws if tenant not set
     */
    public function test_getresolvedslug_throws_if_tenant_not_set()
    {
        $tenantManager = new TenantManager();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Tenant resolution failed/');

        $tenantManager->getResolvedSlug();
    }

    /**
     * Test that getResolvedSlug() returns slug if tenant is set
     */
    public function test_getresolvedslug_returns_slug_when_set()
    {
        $tenantManager = new TenantManager();

        $institution = \Mockery::mock(Institution::class)
            ->shouldIgnoreMissing()
            ->makePartial();
        $institution->slug = 'test-inst';

        $tenantManager->set($institution);

        $this->assertEquals('test-inst', $tenantManager->getResolvedSlug());
    }

    /**
     * Test that regular id() and slug() return null if tenant not set
     * (backward compatibility)
     */
    public function test_id_and_slug_return_null_if_not_set()
    {
        $tenantManager = new TenantManager();

        $this->assertNull($tenantManager->id());
        $this->assertNull($tenantManager->slug());
    }
}
