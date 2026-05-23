<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Klassci\Concerns\HasKlassciEndpointShortcuts;
use App\Services\Klassci\KlassciBatchFetcher;
use App\Services\Klassci\KlassciCacheKeyStrategy;
use App\Services\Klassci\KlassciHttpClient;
use App\Services\Klassci\KlassciRequestMemo;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Service Proxy pour l'API KLASSCI — orchestrateur fin.
 *
 * ## PERF-02 (issue #137) — Architecture 4 collaborateurs
 *
 * Après éclatement architectural (audit `spec-architect` HIGH-1 sur la PR
 * monolithique initiale), ce service est devenu un **orchestrateur** mince qui
 * délègue à 4 collaborateurs spécialisés :
 *
 *   - {@see \App\Services\Klassci\KlassciConfigResolver} — résolution 3-tiers du
 *     tenant URL + token système (priorité personal token → institution token →
 *     system token). Injecté indirectement via {@see KlassciHttpClient}.
 *
 *   - {@see \App\Services\Klassci\KlassciHttpClient} — couche HTTP unifiée avec
 *     `executeHttp()` (DRY fix HIGH-2). Gère SSL, headers, timeout, gestion
 *     d'erreur. Token système OU token utilisateur via param `$overrideToken`.
 *
 *   - {@see \App\Services\Klassci\KlassciRequestMemo} — memoization intra-request.
 *     Tableau privé en mémoire indexé par `xxh3(method+endpoint+params+tokenHash)`.
 *     Vidé sur tout write. Préserve l'isolation cross-user via tokenHash.
 *
 *   - {@see \App\Services\Klassci\KlassciCacheKeyStrategy} — génération clés cache
 *     distribué + soft-invalidation tenant-wide via `invalidatedAt` timestamp.
 *
 * Ce service expose les 15 méthodes endpoint métier (getStructure, getClasses, ...)
 * + 4 méthodes HTTP génériques (get/post/put/delete) + `requestWithUserToken`.
 *
 * ## Flux de résolution (pour les GET)
 *
 *   1. Calcul `memoKey` via `KlassciRequestMemo::memoKey()`.
 *   2. Si memo hit → retour immédiat (pas d'I/O cache, pas de HTTP).
 *   3. Sinon, génération `cacheKey` via `KlassciCacheKeyStrategy` puis
 *      `Cache::remember()` (TTL configurable par appel).
 *   4. Sur cache miss : `KlassciHttpClient::executeHttp('GET', ...)`.
 *   5. Memo populate.
 *
 * ## Flux des writes (POST/PUT/DELETE)
 *
 *   1. `KlassciHttpClient::executeHttp(method, ...)` — réseau direct.
 *   2. `KlassciCacheKeyStrategy::invalidateTenant()` — incrémente le timestamp
 *      `invalidatedAt` (toutes les clés du tenant deviennent obsolètes).
 *   3. `KlassciRequestMemo::clear()` — reset memo intra-request.
 *
 * @see .claude/specs/perf-02-klassci-batch-cache/design.md §1-3
 */
class KlassciProxyService
{
    use HasKlassciEndpointShortcuts;

    private readonly int $cacheTTL;

    private readonly int $userTokenDefaultTTL;

    public function __construct(
        private readonly KlassciHttpClient $http,
        private readonly KlassciRequestMemo $memo,
        private readonly KlassciCacheKeyStrategy $cacheKeys,
        private readonly CacheRepository $cache,
        private readonly KlassciBatchFetcher $batch,
    ) {
        $cacheTtlConfig = config('services.klassci.cache_ttl', 300);
        $this->cacheTTL = is_int($cacheTtlConfig) ? $cacheTtlConfig : 300;

        $userTtlConfig = config('services.klassci.user_token_cache_default_ttl', 300);
        $this->userTokenDefaultTTL = is_int($userTtlConfig) ? $userTtlConfig : 300;
    }

    /**
     * GET avec cache distribué + memoization intra-request.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function get(string $endpoint, array $params = [], ?int $customTTL = null): array
    {
        $memoKey = $this->memo->memoKey('GET', $endpoint, $params, null);
        $memoized = $this->memo->get($memoKey);
        if ($memoized !== null) {
            return $memoized;
        }

        $cacheKey = $this->cacheKeys->generateGlobalKey($endpoint, $params);
        $ttl = $customTTL ?? $this->cacheTTL;

        /** @var array<string, mixed> $result */
        $result = $this->cache->remember($cacheKey, $ttl, function () use ($endpoint, $params): array {
            return $this->http->executeHttp('GET', $endpoint, $params);
        });

        $this->memo->put($memoKey, $result);

        return $result;
    }

    /**
     * POST (pas de cache, invalidation tenant + reset memo).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(string $endpoint, array $data): array
    {
        $result = $this->http->executeHttp('POST', $endpoint, $data);
        $this->invalidateCache($endpoint);

        return $result;
    }

    /**
     * PUT (pas de cache, invalidation tenant + reset memo).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function put(string $endpoint, array $data): array
    {
        $result = $this->http->executeHttp('PUT', $endpoint, $data);
        $this->invalidateCache($endpoint);

        return $result;
    }

    /**
     * DELETE (pas de cache, invalidation tenant + reset memo).
     *
     * @return array<string, mixed>
     */
    public function delete(string $endpoint): array
    {
        $result = $this->http->executeHttp('DELETE', $endpoint);
        $this->invalidateCache($endpoint);

        return $result;
    }

    /**
     * Requête avec token utilisateur spécifique (endpoints personnels).
     *
     * GET : memoization + cache distribué user-token-aware (clé incluant tokenHash
     * pour isolation cross-user).
     *
     * POST/PUT/DELETE : direct HTTP + invalidation tenant + reset memo.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    public function requestWithUserToken(
        string $userToken,
        string $endpoint,
        string $method = 'GET',
        array $data = [],
        ?int $customTTL = null,
    ): array {
        $tokenHash = $this->memo->userTokenHash($userToken);

        if ($method === 'GET') {
            $memoKey = $this->memo->memoKey('GET', $endpoint, $data, $tokenHash);
            $memoized = $this->memo->get($memoKey);
            if ($memoized !== null) {
                return $memoized;
            }

            $cacheKey = $this->cacheKeys->generateUserTokenKey($endpoint, $data, $tokenHash);
            $ttl = $customTTL ?? $this->userTokenDefaultTTL;

            /** @var array<string, mixed> $result */
            $result = $this->cache->remember($cacheKey, $ttl, function () use ($userToken, $endpoint, $method, $data): array {
                return $this->http->executeHttp($method, $endpoint, $data, $userToken);
            });

            $this->memo->put($memoKey, $result);

            return $result;
        }

        // POST/PUT/DELETE — réseau direct + invalidation.
        $result = $this->http->executeHttp($method, $endpoint, $data, $userToken);
        $this->invalidateCache($endpoint);

        return $result;
    }

    /**
     * Invalidation tenant-wide + reset memo intra-request.
     */
    private function invalidateCache(string $endpoint): void
    {
        $this->cacheKeys->invalidateTenant($endpoint);
        $this->memo->clear();
    }

    // ============================================
    // MÉTHODES ENDPOINT SÉMANTIQUES (catalogue KLASSCI)
    // ============================================
    // Les 15 méthodes `getStructure`/`getClasses`/`getMatieres`/`saveNotes`/etc.
    // vivent maintenant dans le trait HasKlassciEndpointShortcuts (`use` ci-dessus).
    // Audit `spec-architect` PR 2 MEDIUM-1 : sépare le catalogue de la mécanique
    // HTTP+cache+memo, fait passer ce fichier sous §1.1 (< 300 lignes).

    // ============================================
    // BATCH HELPERS — PR 2 PERF-02 (issue #135 part b/c)
    // ============================================
    // Délégation à KlassciBatchFetcher (couche 3) — parallélisation Http::pool
    // avec intégration memo intra-request + cache distribué (couches 1+2).
    // Cf. .claude/specs/perf-02-klassci-batch-cache/design.md §4.

    /**
     * Batch fetch N ressources par ID — parallélisé en pools de `pool_size`.
     *
     * @param  array<int>  $ids
     * @return array<int, array<string, mixed>>  Map [id => responseData] ; IDs échoués absents.
     */
    public function fetchManyByEndpoint(
        array $ids,
        string $endpointPattern,
        ?string $userToken = null,
        ?int $customTTL = null,
    ): array {
        return $this->batch->fetchManyByEndpoint($ids, $endpointPattern, $userToken, $customTTL);
    }

    /**
     * @param  array<int>  $matiereIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchManyMatieresDetails(array $matiereIds, string $userToken, ?int $ttl = 600): array
    {
        return $this->batch->fetchManyMatieresDetails($matiereIds, $userToken, $ttl);
    }

    /**
     * @param  array<int>  $classeIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchManyClassesDetails(array $classeIds, string $userToken, ?int $ttl = 600): array
    {
        return $this->batch->fetchManyClassesDetails($classeIds, $userToken, $ttl);
    }

    /**
     * @param  array<int>  $classeIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchManyClasseEtudiants(array $classeIds, ?int $anneeId = null, ?int $ttl = 300): array
    {
        return $this->batch->fetchManyClasseEtudiants($classeIds, $anneeId, $ttl);
    }
}
