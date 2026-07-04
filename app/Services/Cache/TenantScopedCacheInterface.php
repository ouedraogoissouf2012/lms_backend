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
     * Purge physiquement les entrées taguées pour l'institution courante.
     *
     * Sur un store sans support des tags (mode dégradé `database`),
     * l'appel est un no-op journalisé : jamais d'exception, jamais de
     * flush global de repli (interdit par CONTRIBUTING.md §E).
     */
    public function flushTenant(): void;
}
