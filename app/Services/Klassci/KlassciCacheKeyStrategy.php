<?php

declare(strict_types=1);

namespace App\Services\Klassci;

use App\Services\TenantManager;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;

/**
 * PERF-02 (issue #137) — Stratégie de génération de clés cache KLASSCI.
 *
 * Encapsule :
 * - Génération clé tenant-scoped pour les méthodes globales (token système).
 * - Génération clé tenant + tokenHash pour `requestWithUserToken()` GET — isolation
 *   cross-user par construction (clé distincte par token).
 * - Soft-invalidation tenant-wide via `invalidatedAt` timestamp (compatible tous
 *   drivers Cache Laravel — file, database, redis, memcached — sans dépendre de
 *   `Cache::tags()` qui requiert Redis ou Memcached).
 *
 * ## Pourquoi un collaborateur séparé
 *
 * Avant l'éclatement, cette logique vivait dans `KlassciProxyService` et mélangeait
 * 3 axes de changement (config tenant + cache key + invalidation). En l'extrayant :
 * - Test indépendant via `Mockery::mock(KlassciCacheKeyStrategy::class)`.
 * - DI propre : `TenantManager` + `Cache\Repository` (contract Laravel, mockable
 *   via `Illuminate\Support\Facades\Cache::fake()` ou `array` driver).
 *
 * ## Sécurité
 *
 * Le `tokenHash` injecté dans la clé est calculé côté `KlassciRequestMemo::userTokenHash()`
 * — non réversible, jamais loggé brutement. Le `slug` tenant (TenantManager) garantit
 * l'isolation cross-institution.
 *
 * @see .claude/specs/perf-02-klassci-batch-cache/design.md §3
 */
final class KlassciCacheKeyStrategy
{
    public function __construct(
        private readonly TenantManager $tenantManager,
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Clé cache pour les méthodes globales (token système, partagé dans le tenant).
     *
     * Pattern : `klassci_{tenantSlug}_{endpoint}_{paramsHash}_{invalidatedAt}`
     *
     * @param  array<string, mixed>  $params
     */
    public function generateGlobalKey(string $endpoint, array $params): string
    {
        $tenant = $this->resolveTenantKey();
        $paramsHash = md5((string) json_encode($params));
        $invalidatedAt = $this->currentInvalidationTimestamp($tenant);
        // Même normalisation que generateUserTokenKey() (:78) — format de clé
        // homogène quel que soit le store (spec redis-runtime, Requirement 2.2).
        $endpointSlug = str_replace('/', '-', $endpoint);

        return "klassci_{$tenant}_{$endpointSlug}_{$paramsHash}_{$invalidatedAt}";
    }

    /**
     * Clé cache pour `requestWithUserToken()` GET — isolation cross-user.
     *
     * Pattern : `klassci_{tenantSlug}_{tokenHash}_{endpointSlug}_{paramsHash}_{invalidatedAt}`
     *
     * Le `tokenHash` empêche par construction qu'un user reçoive le cache d'un autre :
     * `xxh3(tokenA) ≠ xxh3(tokenB)` → clés distinctes.
     *
     * @param  array<string, mixed>  $params
     */
    public function generateUserTokenKey(string $endpoint, array $params, string $tokenHash): string
    {
        $tenant = $this->resolveTenantKey();
        $paramsHash = md5((string) json_encode($params));
        $invalidatedAt = $this->currentInvalidationTimestamp($tenant);
        $endpointSlug = str_replace('/', '-', $endpoint);

        return "klassci_{$tenant}_{$tokenHash}_{$endpointSlug}_{$paramsHash}_{$invalidatedAt}";
    }

    /**
     * Soft-invalidation tenant-wide.
     *
     * Met à jour le timestamp `invalidated_at` lu par les 2 générateurs de clés.
     * À TTL=∞ (`Cache::forever`) car ce timestamp doit survivre au cache d'app
     * (sinon stale data après expiration).
     *
     * **Effet** : toutes les clés cache du tenant (globales ET user-token-aware)
     * deviennent obsolètes par mismatch — le prochain lookup miss → retouche réseau.
     */
    public function invalidateTenant(string $contextEndpoint): void
    {
        $tenant = $this->resolveTenantKey();
        $invalidationKey = $this->invalidationKeyFor($tenant);

        $this->cache->forever($invalidationKey, now()->timestamp);

        $this->logger->info('KLASSCI cache invalidated', [
            'endpoint' => $contextEndpoint,
            'tenant' => $tenant,
        ]);
    }

    /**
     * Slug tenant unique par institution. Fallback `'default'` pour routes
     * publiques / supradmin sans contexte tenant.
     */
    private function resolveTenantKey(): string
    {
        $slug = $this->tenantManager->slug();

        return $slug ?? 'default';
    }

    private function invalidationKeyFor(string $tenant): string
    {
        return "klassci_{$tenant}_invalidated_at";
    }

    private function currentInvalidationTimestamp(string $tenant): int
    {
        $raw = $this->cache->get($this->invalidationKeyFor($tenant), 0);

        // is_numeric et non is_int : RedisStore stocke les numériques bruts
        // et les restitue en CHAÎNE ("1751...") — un is_int strict retombait
        // silencieusement à 0 et cassait toute la soft-invalidation sous
        // Redis (bug latent attrapé par la jambe CI redis, #374).
        return is_numeric($raw) ? (int) $raw : 0;
    }
}
