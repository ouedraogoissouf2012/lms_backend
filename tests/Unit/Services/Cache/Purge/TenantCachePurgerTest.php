<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cache\Purge;

use App\Services\Cache\Purge\DatabaseCachePurger;
use App\Services\Cache\Purge\NullCachePurger;
use App\Services\Cache\Purge\TaggedCachePurger;
use App\Services\Cache\Purge\TenantCachePurgerFactory;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Repository;
use Illuminate\Cache\TaggedCache;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests unitaires purs des stratégies de purge tenant (#547 R2) et de leur
 * factory de sélection par capacité de store.
 */
class TenantCachePurgerTest extends MockeryTestCase
{
    private LoggerInterface&Mockery\MockInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = Mockery::mock(LoggerInterface::class);
    }

    public function test_tagged_purger_flushes_only_the_namespace_tag(): void
    {
        $tagged = Mockery::mock(TaggedCache::class);
        $tagged->shouldReceive('flush')->once();

        $repository = Mockery::mock(Repository::class);
        $repository->shouldReceive('tags')->once()->with(['institution_7'])->andReturn($tagged);

        (new TaggedCachePurger($repository))->purge('institution_7');
    }

    public function test_null_purger_logs_noop_and_never_flushes(): void
    {
        $this->logger->shouldReceive('warning')
            ->once()
            ->with('tenant_cache.flush_skipped_unsupported_store', ['tag' => 'institution_9']);

        (new NullCachePurger($this->logger))->purge('institution_9');
    }

    public function test_database_purger_deletes_by_prefixed_namespace_pattern(): void
    {
        $grammar = Mockery::mock(Grammar::class);
        $grammar->shouldReceive('wrap')->with('key')->andReturn('"key"');

        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('getGrammar')->once()->andReturn($grammar);
        // Motif = prefix + namespace + ':' + '%', passé en binding paramétré.
        // Le `_` du namespace `institution_3` est échappé (`\_`) : il doit rester
        // littéral, sinon `institution_3` matcherait `institutionX3` — fuite
        // cross-tenant. C'est exactement le but de l'échappement.
        $builder->shouldReceive('whereRaw')
            ->once()
            ->with('"key" LIKE ? ESCAPE \'\\\'', ['lms-cache-institution\\_3:%'])
            ->andReturnSelf();
        $builder->shouldReceive('delete')->once()->andReturn(4);

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->once()->with('cache')->andReturn($builder);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('tenant_cache.database_purge', ['namespace' => 'institution_3', 'deleted' => 4]);

        (new DatabaseCachePurger($connection, 'cache', 'lms-cache-', $this->logger))
            ->purge('institution_3');
    }

    public function test_database_purger_escapes_like_metacharacters_in_namespace(): void
    {
        $grammar = Mockery::mock(Grammar::class);
        $grammar->shouldReceive('wrap')->andReturn('"key"');

        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('getGrammar')->andReturn($grammar);
        // Un namespace pathologique `institution_%` doit voir `_` et `%` échappés
        // pour ne PAS élargir le motif (défense cross-tenant).
        $builder->shouldReceive('whereRaw')
            ->once()
            ->with('"key" LIKE ? ESCAPE \'\\\'', ['institution\\_\\%:%'])
            ->andReturnSelf();
        $builder->shouldReceive('delete')->andReturn(0);

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->andReturn($builder);

        $this->logger->shouldReceive('info');

        (new DatabaseCachePurger($connection, 'cache', '', $this->logger))
            ->purge('institution_%');
    }

    public function test_factory_selects_tagged_purger_when_store_supports_tags(): void
    {
        $repository = Mockery::mock(Repository::class);
        $repository->shouldReceive('supportsTags')->once()->andReturnTrue();

        $purger = (new TenantCachePurgerFactory($this->logger))->make($repository);

        self::assertInstanceOf(TaggedCachePurger::class, $purger);
    }

    public function test_factory_selects_database_purger_for_database_store(): void
    {
        $store = Mockery::mock(DatabaseStore::class);
        $store->shouldReceive('getConnection')->andReturn(Mockery::mock(ConnectionInterface::class));
        $store->shouldReceive('getPrefix')->andReturn('lms-cache-');

        $repository = Mockery::mock(Repository::class);
        $repository->shouldReceive('supportsTags')->once()->andReturnFalse();
        $repository->shouldReceive('getStore')->once()->andReturn($store);

        $purger = (new TenantCachePurgerFactory($this->logger))->make($repository);

        self::assertInstanceOf(DatabaseCachePurger::class, $purger);
    }

    public function test_factory_falls_back_to_null_purger_for_unsupported_store(): void
    {
        $repository = Mockery::mock(Repository::class);
        $repository->shouldReceive('supportsTags')->once()->andReturnFalse();
        $repository->shouldReceive('getStore')->once()->andReturn(new ArrayStore);

        $purger = (new TenantCachePurgerFactory($this->logger))->make($repository);

        self::assertInstanceOf(NullCachePurger::class, $purger);
    }
}
