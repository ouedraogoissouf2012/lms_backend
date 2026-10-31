<?php

namespace Tests\Unit\Services\Cache;

use App\Models\Institution;
use App\Services\Cache\Purge\TenantCachePurgerInterface;
use App\Services\Cache\TenantScopedCache;
use App\Services\TenantManager;
use Illuminate\Cache\Repository;
use Illuminate\Cache\TaggedCache;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;

/**
 * Tests unitaires purs (Mockery, aucun vrai store) de TenantScopedCache :
 * les deux branches supportsTags() true/false pour remember(), le namespacing
 * de clé sur store sans tags (#547), la délégation de flushTenant() au purger
 * injecté, et le cas « tenant non résolu » (namespace institution_none).
 *
 * Spec: .claude/specs/redis-runtime (Req. 4.x) + .claude/specs/547-pdf-async-cache-purge (Req. 2.x).
 */
class TenantScopedCacheTest extends MockeryTestCase
{
    private Repository&Mockery\MockInterface $repository;

    private TenantManager $tenantManager;

    private TenantCachePurgerInterface&Mockery\MockInterface $purger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(Repository::class);
        $this->tenantManager = new TenantManager;
        $this->purger = Mockery::mock(TenantCachePurgerInterface::class);
    }

    private function makeCache(): TenantScopedCache
    {
        return new TenantScopedCache($this->repository, $this->tenantManager, $this->purger);
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

    public function test_remember_namespaces_key_when_store_does_not_support_tags(): void
    {
        $this->setTenant(42);
        $callback = fn (): string => 'valeur';

        $this->repository->shouldReceive('supportsTags')->once()->andReturnFalse();
        $this->repository->shouldNotReceive('tags');
        // #547 : la clé est préfixée du namespace tenant pour rester ciblable
        // par la purge database (LIKE institution_42:%).
        $this->repository->shouldReceive('remember')
            ->once()
            ->with('institution_42:cle', 300, $callback)
            ->andReturn('valeur');

        self::assertSame('valeur', $this->makeCache()->remember('cle', 300, $callback));
    }

    public function test_remember_does_not_double_namespace_already_prefixed_key(): void
    {
        $this->setTenant(42);
        $callback = fn (): string => 'valeur';

        $this->repository->shouldReceive('supportsTags')->once()->andReturnFalse();
        $this->repository->shouldReceive('remember')
            ->once()
            ->with('institution_42:deja', 300, $callback)
            ->andReturn('valeur');

        self::assertSame('valeur', $this->makeCache()->remember('institution_42:deja', 300, $callback));
    }

    public function test_remember_namespaces_institution_none_when_tenant_unresolved(): void
    {
        // Aucun set() : TenantManager::id() === null.
        $callback = fn (): string => 'valeur';

        $this->repository->shouldReceive('supportsTags')->once()->andReturnFalse();
        $this->repository->shouldReceive('remember')
            ->once()
            ->with('institution_none:cle', 300, $callback)
            ->andReturn('valeur');

        self::assertSame('valeur', $this->makeCache()->remember('cle', 300, $callback));
    }

    public function test_flush_tenant_delegates_to_purger_with_current_namespace(): void
    {
        $this->setTenant(7);

        $this->purger->shouldReceive('purge')->once()->with('institution_7');

        $this->makeCache()->flushTenant();
    }

    public function test_flush_tenant_uses_institution_none_when_tenant_unresolved(): void
    {
        // Aucun set() (Requirement 3.2).
        $this->purger->shouldReceive('purge')->once()->with('institution_none');

        $this->makeCache()->flushTenant();
    }

    /**
     * rememberFor() : la durée se décide sur le résultat. Même tag que
     * remember(), pour que la purge tenant atteigne l'entrée.
     */
    public function test_remember_for_puts_with_the_ttl_computed_from_the_value_when_store_supports_tags(): void
    {
        $this->setTenant(42);

        $tagged = Mockery::mock(TaggedCache::class);
        $tagged->shouldReceive('get')->once()->with('cle')->andReturnNull();
        $tagged->shouldReceive('put')->once()->with('cle', ['success' => false], 30);

        $this->repository->shouldReceive('supportsTags')->once()->andReturnTrue();
        $this->repository->shouldReceive('tags')->once()->with(['institution_42'])->andReturn($tagged);

        $valeur = $this->makeCache()->rememberFor(
            'cle',
            fn (): array => ['success' => false],
            fn (mixed $v): int => $v === ['success' => false] ? 30 : 300,
        );

        self::assertSame(['success' => false], $valeur);
    }

    public function test_remember_for_namespaces_key_when_store_does_not_support_tags(): void
    {
        $this->setTenant(42);

        $this->repository->shouldReceive('supportsTags')->once()->andReturnFalse();
        $this->repository->shouldNotReceive('tags');
        $this->repository->shouldReceive('get')->once()->with('institution_42:cle')->andReturnNull();
        $this->repository->shouldReceive('put')->once()->with('institution_42:cle', 'valeur', 300);

        self::assertSame('valeur', $this->makeCache()->rememberFor('cle', fn (): string => 'valeur', fn (): int => 300));
    }

    public function test_remember_for_keeps_nothing_when_the_ttl_is_zero(): void
    {
        $this->setTenant(42);

        $this->repository->shouldReceive('supportsTags')->once()->andReturnFalse();
        $this->repository->shouldReceive('get')->once()->andReturnNull();
        $this->repository->shouldNotReceive('put');

        self::assertSame('valeur', $this->makeCache()->rememberFor('cle', fn (): string => 'valeur', fn (): int => 0));
    }

    public function test_remember_for_serves_a_hit_without_calling_back(): void
    {
        $this->setTenant(42);

        $this->repository->shouldReceive('supportsTags')->once()->andReturnFalse();
        $this->repository->shouldReceive('get')->once()->with('institution_42:cle')->andReturn('en cache');
        $this->repository->shouldNotReceive('put');

        $valeur = $this->makeCache()->rememberFor(
            'cle',
            static fn (): never => throw new \LogicException('le callback ne doit pas être appelé sur un succès de cache'),
            static fn (): never => throw new \LogicException('la durée ne doit pas être calculée sur un succès de cache'),
        );

        self::assertSame('en cache', $valeur);
    }
}
