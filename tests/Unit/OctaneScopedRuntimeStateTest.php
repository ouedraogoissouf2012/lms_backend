<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Institution;
use App\Services\Klassci\KlassciRequestMemo;
use App\Services\TenantManager;
use Tests\TestCase;

final class OctaneScopedRuntimeStateTest extends TestCase
{
    public function test_tenant_and_klassci_memo_are_scoped_between_octane_requests(): void
    {
        $tenant = new Institution(['slug' => 'tenant-a']);
        $tenant->id = 123;

        $tenantManager = app(TenantManager::class);
        $memo = app(KlassciRequestMemo::class);

        $tenantManager->set($tenant);
        $memo->put('request-key', ['value' => true]);

        self::assertSame(123, $tenantManager->id());
        self::assertSame(1, $memo->size());

        app()->forgetScopedInstances();

        $nextTenantManager = app(TenantManager::class);
        $nextMemo = app(KlassciRequestMemo::class);

        self::assertNotSame($tenantManager, $nextTenantManager);
        self::assertNotSame($memo, $nextMemo);
        self::assertNull($nextTenantManager->get());
        self::assertSame(0, $nextMemo->size());
    }
}
