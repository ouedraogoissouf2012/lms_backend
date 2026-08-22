<?php

declare(strict_types=1);

namespace App\Services\Cache\Purge;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Repository;
use Psr\Log\LoggerInterface;

/**
 * Sélectionne la stratégie de purge tenant selon la capacité du store cache
 * actif (#547 R2.6). Une seule raison de changer : l'ajout d'un nouveau type de
 * store (OCP — on ajoute une branche + une implémentation, sans toucher
 * `TenantScopedCache`).
 *
 * Priorité :
 *   1. tags natifs (Redis/Memcached) → purge immédiate par tag ;
 *   2. store `database` → DELETE ciblé par motif de clé ;
 *   3. sinon (`file`, `array`) → no-op journalisé.
 */
final class TenantCachePurgerFactory
{
    /**
     * @param  string  $databaseCacheTable  Nom de la table du store `database`
     *                                      (injecté depuis la config par le
     *                                      provider — factory pure, testable
     *                                      sans booter l'app).
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $databaseCacheTable = 'cache',
    ) {}

    public function make(Repository $cache): TenantCachePurgerInterface
    {
        if ($cache->supportsTags()) {
            return new TaggedCachePurger($cache);
        }

        $store = $cache->getStore();

        if ($store instanceof DatabaseStore) {
            return new DatabaseCachePurger(
                $store->getConnection(),
                $this->databaseCacheTable,
                $store->getPrefix(),
                $this->logger,
            );
        }

        return new NullCachePurger($this->logger);
    }
}
