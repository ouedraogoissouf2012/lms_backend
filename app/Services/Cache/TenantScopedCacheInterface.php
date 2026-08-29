<?php

namespace App\Services\Cache;

/**
 * Cache lecture-à-travers scopé au tenant courant.
 *
 * Cette interface est la SEULE dépendance de cache que le code métier
 * (ex. KlassciProxyService) doit connaître — jamais l'implémentation
 * concrète, jamais le Repository du framework (§1.6 D, Dependency
 * Inversion). Elle masque aux consommateurs le détail « le store actif
 * supporte ou non les tags » : le tagging par institution et sa
 * dégradation sans erreur sont la responsabilité de l'implémentation.
 */
interface TenantScopedCacheInterface
{
    /**
     * Lit la clé en cache ou exécute le callback et mémorise son résultat,
     * l'entrée étant taguée `institution_{id}` quand le store le permet.
     *
     * @param  \Closure(): mixed  $callback
     */
    public function remember(string $key, int $ttl, \Closure $callback): mixed;

    /**
     * Purge physiquement les entrées cache de l'institution courante.
     *
     * La stratégie dépend du store actif (#547) :
     *   - Redis/Memcached → purge par tag `institution_{id}` ;
     *   - `database`      → DELETE ciblé par motif de clé (namespace tenant) ;
     *   - `file`/`array`  → no-op journalisé (pas de purge ciblée native).
     *
     * Dans tous les cas : jamais d'exception, jamais de flush global de repli
     * cross-tenant (interdit par CONTRIBUTING.md §E).
     */
    public function flushTenant(): void;
}
