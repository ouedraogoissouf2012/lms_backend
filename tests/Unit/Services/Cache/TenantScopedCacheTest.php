<?php

namespace Tests\Unit\Services\Cache;

use App\Models\Institution;
use App\Services\Cache\TenantScopedCache;
use App\Services\TenantManager;
use Illuminate\Cache\Repository;
use Illuminate\Cache\TaggedCache;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests unitaires purs (Mockery, aucun vrai store) de TenantScopedCache :
 * les deux branches supportsTags() true/false pour remember() et
 * flushTenant(), plus le cas « tenant non résolu » (tag institution_none).
 *
 * Spec: .claude/specs/redis-runtime (Requirements 4.1, 4.2, 4.4, 4.5, 7.5)
 */
class TenantScopedCacheTest extends MockeryTestCase
{
    private Repository&Mockery\MockInterface $repository;

    private TenantManager $tenantManager;

    private LoggerInterface&Mockery\MockInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(Repository::class);
        $this->tenantManager = new TenantManager;
        $this->logger = Mockery::mock(LoggerInterface::class);
    }

    private function makeCache(): TenantScopedCache
    {
        return new TenantScopedCache($this->repository, $this->tenantManager, $this->logger);
    }

    private function setTenant(int $id): void
    {
        $institution = new Institution;
        $institution->id = $id;
        $this->tenantManager->set($institution);
    }

    public function test_remember_uses_institution_tag_when_store_supports_tags(): void
    {
        $this->setTenant(42);
        $callback = fn (): string => 'valeur';

        $tagged = Mockery::mock(TaggedCache::class);
        $tagged->shouldReceive('remember')
            ->once()
            ->with('cle', 300, $callback)
            ->andReturn('valeur');

        $this->repository->shouldReceive('supportsTags')->once()->andReturnTrue();
        $this->repository->shouldReceive('tags')
            ->once()
            ->with(['institution_42'])
            ->andReturn($tagged);

        self::assertSame('valeur', $this->makeCache()->remember('cle', 300, $callback));
    }

    public function test_remember_skips_tags_when_store_does_not_support_them(): void
    {
        $this->setTenant(42);
        $callback = fn (): string => 'valeur';

        $this->repository->shouldReceive('supportsTags')->once()->andReturnFalse();
        $this->repository->shouldNotReceive('tags');
        $this->repository->shouldReceive('remember')
            ->once()
            ->with('cle', 300, $callback)
            ->andReturn('valeur');

        self::assertSame('valeur', $this->makeCache()->remember('cle', 300, $callback));
    }

    public function test_remember_tags_institution_none_when_tenant_unresolved(): void
    {
        // Aucun set() : TenantManager::id() === null (Requirement 4.4).
        $callback = fn (): string => 'valeur';

        $tagged = Mockery::mock(TaggedCache::class);
        $tagged->shouldReceive('remember')->once()->andReturn('valeur');

        $this->repository->shouldReceive('supportsTags')->once()->andReturnTrue();
        $this->repository->shouldReceive('tags')
            ->once()
            ->with(['institution_none'])
            ->andReturn($tagged);

        self::assertSame('valeur', $this->makeCache()->remember('cle', 300, $callback));
    }

    public function test_flush_tenant_flushes_only_the_institution_tag_when_supported(): void
    {
        $this->setTenant(7);

        $tagged = Mockery::mock(TaggedCache::class);
        $tagged->shouldReceive('flush')->once()->andReturnTrue();

        $this->repository->shouldReceive('supportsTags')->once()->andReturnTrue();
        $this->repository->shouldReceive('tags')
            ->once()
            ->with(['institution_7'])
            ->andReturn($tagged);

        $this->makeCache()->flushTenant();
    }

    public function test_flush_tenant_is_a_logged_noop_when_tags_unsupported(): void
    {
        $this->setTenant(7);

        $this->repository->shouldReceive('supportsTags')->once()->andReturnFalse();
        $this->repository->shouldNotReceive('tags');

        // Jamais de flush global de repli (CONTRIBUTING.md §E) : uniquement
        // un warning explicite, puis retour sans effet (Requirement 4.5/6.2).
        $this->logger->shouldReceive('warning')
            ->once()
            ->with('tenant_cache.flush_skipped_unsupported_store', ['tag' => 'institution_7']);

        $this->makeCache()->flushTenant();
    }
}
