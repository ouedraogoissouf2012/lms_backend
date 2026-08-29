<?php

declare(strict_types=1);

namespace App\Services\Cache\Purge;

use Illuminate\Cache\Repository;

/**
 * Purge par tag — stores Redis/Memcached (#547 R2.2).
 *
 * Conserve le comportement historique de `TenantScopedCache::flushTenant()` :
 * `tags([namespace])->flush()` libère immédiatement la mémoire du tenant. Le
 * namespace est utilisé comme tag (il l'était déjà : `institution_{id}`).
 */
final class TaggedCachePurger implements TenantCachePurgerInterface
{
    public function __construct(
        private readonly Repository $cache,
    ) {}

    public function purge(string $namespace): void
    {
        $this->cache->tags([$namespace])->flush();
    }
}
