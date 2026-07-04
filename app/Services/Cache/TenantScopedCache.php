<?php

namespace App\Services\Cache;

use App\Services\TenantManager;
use Illuminate\Cache\Repository;
use Psr\Log\LoggerInterface;

/**
 * Implémentation par défaut de TenantScopedCacheInterface.
 *
 * Frontière d'adaptation entre le contrat générique du framework et la
 * capacité « tags » : injecte la classe CONCRÈTE Illuminate\Cache\Repository
 * (et non le contrat Illuminate\Contracts\Cache\Repository) car tags() et
 * supportsTags() n'existent que sur la classe concrète — décision motivée
 * dans .claude/specs/redis-runtime/design.md. Le code métier, lui, ne
 * dépend que de l'interface (§1.6 D respecté là où il compte).
 */
class TenantScopedCache implements TenantScopedCacheInterface
{
    public function __construct(
        private readonly Repository $cache,
        private readonly TenantManager $tenantManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function remember(string $key, int $ttl, \Closure $callback): mixed
    {
        if ($this->cache->supportsTags()) {
            return $this->cache->tags([$this->tenantTag()])->remember($key, $ttl, $callback);
        }

        return $this->cache->remember($key, $ttl, $callback);
    }

    public function flushTenant(): void
    {
        $tag = $this->tenantTag();

        if (! $this->cache->supportsTags()) {
            // Jamais de flush global de repli : ce serait le Cache::flush()
            // cross-tenant interdit (CONTRIBUTING.md §E). Les entrées restent
            // orphelines et expirent par TTL (Requirement 6.2).
            $this->logger->warning('tenant_cache.flush_skipped_unsupported_store', ['tag' => $tag]);

            return;
        }

        $this->cache->tags([$tag])->flush();
    }

    /**
     * Tag `institution_{id}` — l'id entier, stable, jamais le slug.
     * Tenant non résolu → `institution_none`, isolé des tenants réels
     * (Requirement 4.4).
     */
    private function tenantTag(): string
    {
        $id = $this->tenantManager->id();

        return $id === null ? 'institution_none' : "institution_{$id}";
    }
}
