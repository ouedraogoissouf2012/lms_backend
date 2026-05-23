<?php

declare(strict_types=1);

namespace App\Services\Klassci;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;

/**
 * PERF-02 (issue #135) — Couche 3 : batch fetcher parallélisé via `Http::pool`.
 *
 * ## Objectif
 *
 * Élimine les N+1 HTTP KLASSCI identifiés sur 7 callsites (cf. issue #135).
 * Pour une liste d'IDs, parallélise les appels HTTP en pools de N requêtes
 * concurrentes (`KLASSCI_POOL_SIZE`, défaut 4).
 *
 * ## Intégration avec les couches 1+2 (livrées en PR #136)
 *
 * Le fetcher invoque dans l'ordre, pour chaque ID :
 *
 *   1. **Memo intra-request** ({@see KlassciRequestMemo}) — si l'ID est déjà
 *      memoizé, court-circuite cache + HTTP.
 *
 *   2. **Cache distribué** (via {@see KlassciCacheKeyStrategy}) — si l'ID est
 *      en cache, court-circuite HTTP, populate memo, ajoute au résultat.
 *
 *   3. **Pool HTTP** — les IDs restants sont distribués en pools de `pool_size`
 *      requêtes parallèles via `Http::pool()`. Sur réponse OK : store cache +
 *      memo + résultat. Sur réponse failed : log + omet l'ID du map (sans throw,
 *      pour préserver la sémantique pré-refactor où les callers gèrent l'absence).
 *
 * ## Tolérance aux erreurs partielles
 *
 * Si 1 ID sur 20 échoue (timeout, HTTP 5xx KLASSCI), throw casserait toute la
 * batch. Préservation sémantique : log + omission (le caller détecte via
 * `isset($result[$id])` ou `$result[$id] ?? null` — comme dans les `??` partout).
 *
 * @see .claude/specs/perf-02-klassci-batch-cache/design.md §4
 */
final class KlassciBatchFetcher
{
    private readonly int $poolSize;

    private readonly int $timeout;

    private readonly bool $sslVerify;

    private readonly int $defaultTTL;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly KlassciRequestMemo $memo,
        private readonly KlassciCacheKeyStrategy $cacheKeys,
        private readonly KlassciConfigResolver $config,
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger,
    ) {
        // Cap dur à 32 : sanity check défense-en-profondeur contre une mauvaise
        // config ops (`KLASSCI_POOL_SIZE=10000` → DoS sortant + épuisement
        // file descriptors PHP-FPM). Audit `spec-security` PR 2 MEDIUM-1.
        $poolSizeConfig = config('services.klassci.pool_size', 4);
        $this->poolSize = is_int($poolSizeConfig) && $poolSizeConfig > 0
            ? min($poolSizeConfig, 32)
            : 4;

        $timeoutConfig = config('services.klassci.timeout', 30);
        $this->timeout = is_int($timeoutConfig) ? $timeoutConfig : 30;

        $this->sslVerify = (bool) config('services.klassci.ssl_verify', true);

        $defaultTtlConfig = config('services.klassci.user_token_cache_default_ttl', 300);
        $this->defaultTTL = is_int($defaultTtlConfig) ? $defaultTtlConfig : 300;
    }

    /**
     * Fetch N ressources par ID en parallèle, avec intégration memo + cache.
     *
     * @security Le caller DOIT garantir que `$ids` n'inclut QUE des ressources
     *           accessibles par `$userToken` (ou par le token système si null).
     *           Ce service NE FAIT PAS d'auth check : il s'appuie sur KLASSCI
     *           pour refuser un ID hors scope (log + omission du map résultat).
     *           **Ne JAMAIS** passer directement `$request->input('ids')` brut
     *           sans filtrage tenant/ownership préalable côté caller — vecteur
     *           IDOR garanti si KLASSCI ne tient pas son côté du contrat.
     *
     * @param  array<int>  $ids               Liste d'IDs à résoudre
     * @param  string  $endpointPattern   Pattern avec `{id}` (ex: `"matieres/{id}"`)
     * @param  string|null  $userToken     Token utilisateur ou null pour le token système
     * @param  int|null  $customTTL        Override TTL cache (secondes)
     * @return array<int, array<string, mixed>>  Map [id => responseData]. IDs échoués absents.
     */
    public function fetchManyByEndpoint(
        array $ids,
        string $endpointPattern,
        ?string $userToken = null,
        ?int $customTTL = null,
    ): array {
        if ($ids === []) {
            return [];
        }

        $uniqueIds = array_values(array_unique($ids));
        $ttl = $customTTL ?? $this->defaultTTL;
        $tokenHash = $userToken !== null ? $this->memo->userTokenHash($userToken) : null;

        // Étape 1 — Résoudre les IDs via memo + cache distribué.
        [$resolved, $needsFetch] = $this->resolveFromMemoAndCache($uniqueIds, $endpointPattern, $tokenHash);

        if ($needsFetch === []) {
            return $resolved;
        }

        // Étape 2 — Pool HTTP parallèle sur les IDs restants.
        $effectiveToken = $userToken ?? $this->config->token();
        $baseUrl = $this->config->baseUrl() ?? '';

        foreach (array_chunk($needsFetch, $this->poolSize, true) as $batch) {
            $responses = $this->http->pool($this->buildPoolRequests($batch, $baseUrl, $effectiveToken));
            $this->persistBatchResponses($batch, $responses, $ttl, $resolved);
        }

        return $resolved;
    }

    /**
     * Étape 1 — Filtre les IDs déjà résolus (memo + cache) des IDs à fetcher.
     *
     * @param  array<int>  $uniqueIds
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array{endpoint: string, memoKey: string, cacheKey: string}>}
     */
    private function resolveFromMemoAndCache(array $uniqueIds, string $endpointPattern, ?string $tokenHash): array
    {
        $resolved = [];
        $needsFetch = [];

        foreach ($uniqueIds as $id) {
            $endpoint = $this->concretizeEndpoint($endpointPattern, $id);

            $memoKey = $this->memo->memoKey('GET', $endpoint, [], $tokenHash);
            $memoized = $this->memo->get($memoKey);
            if ($memoized !== null) {
                $resolved[$id] = $memoized;
                continue;
            }

            $cacheKey = $tokenHash !== null
                ? $this->cacheKeys->generateUserTokenKey($endpoint, [], $tokenHash)
                : $this->cacheKeys->generateGlobalKey($endpoint, []);

            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
                $this->memo->put($memoKey, $cached);
                $resolved[$id] = $cached;
                continue;
            }

            $needsFetch[$id] = [
                'endpoint' => $endpoint,
                'memoKey'  => $memoKey,
                'cacheKey' => $cacheKey,
            ];
        }

        return [$resolved, $needsFetch];
    }

    /**
     * Étape 2a — Construit le callback `Http::pool` pour un batch d'IDs.
     *
     * @param  array<int, array{endpoint: string, memoKey: string, cacheKey: string}>  $batch
     */
    private function buildPoolRequests(array $batch, string $baseUrl, ?string $effectiveToken): \Closure
    {
        return function ($pool) use ($batch, $baseUrl, $effectiveToken) {
            $requests = [];
            foreach ($batch as $id => $meta) {
                $url = $baseUrl . '/' . ltrim($meta['endpoint'], '/');

                // PR 2 audit `spec-architect` MEDIUM-2 : factorisation HTTP-builder
                // via helper statique pur sur KlassciHttpClient (headers + SSL + token).
                $req = KlassciHttpClient::decorateRequest(
                    $pool->as((string) $id)->timeout($this->timeout),
                    $url,
                    $this->sslVerify,
                    $effectiveToken,
                );

                $requests[] = $req->get($url);
            }

            return $requests;
        };
    }

    /**
     * Étape 2b — Persiste les réponses d'un batch : OK → cache+memo+résultat,
     * failed → log + omission de l'ID du map (pas de throw, sémantique préservée).
     *
     * @param  array<int, array{endpoint: string, memoKey: string, cacheKey: string}>  $batch
     * @param  array<string, mixed>  $responses
     * @param  array<int, array<string, mixed>>  $resolved
     */
    private function persistBatchResponses(array $batch, array $responses, int $ttl, array &$resolved): void
    {
        foreach ($batch as $id => $meta) {
            $response = $responses[(string) $id] ?? null;
            if (!$response instanceof Response || !$response->ok()) {
                $status = $response instanceof Response ? $response->status() : 'no-response';
                $this->logger->error('KLASSCI batch fetch failed', [
                    'id'       => $id,
                    'endpoint' => $meta['endpoint'],
                    'status'   => $status,
                ]);
                continue;
            }

            $payload = $response->json();
            if (!is_array($payload)) {
                $payload = [];
            }

            /** @var array<string, mixed> $payload */
            $this->cache->put($meta['cacheKey'], $payload, $ttl);
            $this->memo->put($meta['memoKey'], $payload);
            $resolved[$id] = $payload;
        }
    }

    /**
     * Helper : batch des détails matières.
     *
     * @param  array<int>  $matiereIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchManyMatieresDetails(array $matiereIds, string $userToken, ?int $ttl = 600): array
    {
        return $this->fetchManyByEndpoint($matiereIds, 'matieres/{id}', $userToken, $ttl);
    }

    /**
     * Helper : batch des détails classes (user-token-aware).
     *
     * @param  array<int>  $classeIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchManyClassesDetails(array $classeIds, string $userToken, ?int $ttl = 600): array
    {
        return $this->fetchManyByEndpoint($classeIds, 'classes/{id}', $userToken, $ttl);
    }

    /**
     * Helper : batch des étudiants par classe — utilise le token système (cron/job).
     *
     * @param  array<int>  $classeIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchManyClasseEtudiants(array $classeIds, ?int $anneeId = null, ?int $ttl = 300): array
    {
        $pattern = $anneeId !== null
            ? "classes/{id}/etudiants?annee_id={$anneeId}"
            : 'classes/{id}/etudiants';

        return $this->fetchManyByEndpoint($classeIds, $pattern, null, $ttl);
    }

    /**
     * Substitue `{id}` dans le pattern d'endpoint.
     *
     * Exemple : `"matieres/{id}"` + `id=42` → `"matieres/42"`.
     */
    private function concretizeEndpoint(string $pattern, int $id): string
    {
        return str_replace('{id}', (string) $id, $pattern);
    }
}
