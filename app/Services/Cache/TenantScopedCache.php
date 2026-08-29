<?php

namespace App\Services\Cache;

use App\Services\Cache\Purge\TenantCachePurgerInterface;
use App\Services\TenantManager;
use Illuminate\Cache\Repository;

/**
 * Implémentation par défaut de TenantScopedCacheInterface.
 *
 * Frontière d'adaptation entre le contrat générique du framework et la
 * capacité « tags » : injecte la classe CONCRÈTE Illuminate\Cache\Repository
 * (et non le contrat Illuminate\Contracts\Cache\Repository) car tags() et
 * supportsTags() n'existent que sur la classe concrète — décision motivée
 * dans .claude/specs/redis-runtime/design.md. Le code métier, lui, ne
 * dépend que de l'interface (§1.6 D respecté là où il compte).
 *
 * #547 — la purge physique tenant est déléguée à un {@see TenantCachePurgerInterface}
 * choisi par capacité du store (tags / database / no-op) : `flushTenant()` n'a plus
 * de branche `if store`, et sur un store sans tags les clés `remember()` sont
 * préfixées du namespace tenant pour rester ciblables par la purge `database`.
 */
class TenantScopedCache implements TenantScopedCacheInterface
{
    public function __construct(
        private readonly Repository $cache,
        private readonly TenantManager $tenantManager,
        private readonly TenantCachePurgerInterface $purger,
    ) {}

    public function remember(string $key, int $ttl, \Closure $callback): mixed
    {
        if ($this->cache->supportsTags()) {
            return $this->cache->tags([$this->tenantTag()])->remember($key, $ttl, $callback);
        }

        // Store sans tags (ex. `database`) : on encapsule la clé dans le
        // namespace tenant pour que la purge ciblée (DatabaseCachePurger)
        // puisse la retrouver par motif `LIKE {prefix}{namespace}:%`.
        return $this->cache->remember($this->namespaced($key), $ttl, $callback);
    }

    public function flushTenant(): void
    {
        $this->purger->purge($this->tenantTag());
    }

    /**
     * Préfixe la clé du namespace tenant, idempotent : une clé déjà namespacée
     * (ex. clés KLASSCI portant déjà `institution_X:`) n'est pas double-préfixée.
     */
    private function namespaced(string $key): string
    {
        $namespace = $this->tenantTag();

        return str_starts_with($key, $namespace.':') ? $key : "{$namespace}:{$key}";
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
