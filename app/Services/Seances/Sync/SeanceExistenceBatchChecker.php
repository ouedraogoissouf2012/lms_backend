<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

use App\Services\Klassci\KlassciHttpClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Vérifie en pool HTTP parallèle si des séances existent toujours côté
 * KLASSCI — élimine le N+1 HTTP de `CleanObsoleteSeances` (#516).
 *
 * ## `baseUrl`/`token` en PARAMÈTRE explicite, jamais résolus en interne
 * (révisé après audit `spec-architect` #516)
 *
 * Un 1er essai injectait `KlassciConfigResolver` (directement, puis via
 * `Container::make()` pour contourner sa mémorisation par instance) pour
 * résoudre la config depuis le tenant `TenantManager` courant. L'audit
 * architecture a montré que c'était le mauvais outil : `KlassciConfigResolver`
 * est un résolveur 3-tiers conçu pour un contexte HTTP-utilisateur (token
 * personnel Sanctum en priorité 1/2) — TOUJOURS mort dans un Job de queue
 * (jamais de guard Sanctum authentifié), où seule la priorité 3
 * (`Institution::getKlassciConfig()`) s'applique. Or `CleanObsoleteSeances`
 * a DÉJÀ l'`Institution` en main à l'appel de `checkMany()` (c'est elle qui
 * détermine le lot à vérifier). Lui faire porter `klassci_api_url`/
 * `klassci_api_token` en paramètre explicite élimine d'un coup : la
 * dépendance à `Container` (service locator — `Container::make()` ne révèle
 * pas la vraie dépendance dans le constructeur, rendait la classe non
 * mockable sans lier des instances dans le vrai conteneur Laravel), la
 * dépendance à `KlassciConfigResolver`, et tout risque de staleness (il n'y a
 * plus aucun état interne à figer). Cette classe ne lit donc plus AUCUN état
 * ambiant (ni `TenantManager`, ni `Container`) — entrées 100% explicites.
 *
 * ## Pourquoi pas `KlassciBatchFetcher::fetchManyByEndpoint()`
 *
 * Ce collaborateur partagé omet silencieusement tout ID en échec (log +
 * absence du résultat), sans distinguer un 404 confirmé (séance réellement
 * supprimée) d'une erreur transitoire (réseau, 5xx). Pour un mécanisme dont
 * la seule fonction est de décider quoi archiver, cette distinction est
 * obligatoire — d'où ce collaborateur dédié, à 3 états explicites
 * ({@see SeanceCheckResult}).
 *
 * ## Limite honnête (Q15 self-critique, cf. design.md #516 §1)
 *
 * Ni circuit breaker ni cache ici, contrairement à `KlassciBatchFetcher` —
 * délibéré pour le cache (une vérification d'existence ne doit jamais servir
 * une réponse obsolète), non résolu pour le circuit breaker (défaut
 * pré-existant de l'infrastructure batch partagée, `KlassciCircuitBreaker`
 * hors du périmètre de fichiers assigné à cette issue).
 *
 * @see PRODUCTION_STANDARDS.md §1.1 (≤300 lignes) · §1.6 D (DI strict)
 */
final class SeanceExistenceBatchChecker
{
    private readonly int $poolSize;

    private readonly int $timeout;

    private readonly int $connectTimeout;

    private readonly bool $sslVerify;

    public function __construct(
        private readonly HttpFactory $http,
    ) {
        // Même cap dur que KlassciBatchFetcher (défense en profondeur contre
        // une config ops erronée) — cohérence Q6.
        $this->poolSize = min(self::positiveIntConfig('services.klassci.pool_size', 4), 32);
        $this->connectTimeout = self::positiveIntConfig('services.klassci.connect_timeout', 2);
        $this->timeout = self::positiveIntConfig('services.klassci.timeout', 5);
        $this->sslVerify = (bool) config('services.klassci.ssl_verify', true);
    }

    /**
     * Lit une valeur de config numérique positive, avec repli sur `$default`
     * si absente/invalide — évite de répéter 3× le même ternaire défensif.
     *
     * @param  positive-int  $default
     * @return positive-int
     */
    private static function positiveIntConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /**
     * @param  array<int>  $klassciSeanceIds
     * @param  string  $baseUrl  URL de base KLASSCI de L'INSTITUTION concernée
     *                           (ex. `$institution->klassci_api_url`) — jamais
     *                           résolue en interne, voir docblock de classe.
     * @param  ?string  $token  Token système de CETTE MÊME institution.
     * @return array<int, SeanceCheckResult>
     */
    public function checkMany(array $klassciSeanceIds, string $baseUrl, ?string $token): array
    {
        if ($klassciSeanceIds === []) {
            return [];
        }

        $results = [];
        foreach (array_chunk(array_values(array_unique($klassciSeanceIds)), $this->poolSize) as $batch) {
            $responses = $this->http->pool(fn ($pool) => $this->buildPoolRequests($pool, $batch, $baseUrl, $token));

            foreach ($batch as $id) {
                $results[$id] = $this->classify($responses[(string) $id] ?? null);
            }
        }

        return $results;
    }

    /**
     * @param  array<int>  $batch
     * @return array<int, \Illuminate\Http\Client\Response|\Illuminate\Http\Client\Pool>
     */
    private function buildPoolRequests(\Illuminate\Http\Client\Pool $pool, array $batch, string $baseUrl, ?string $token): array
    {
        $requests = [];
        foreach ($batch as $id) {
            $url = "{$baseUrl}/seances/{$id}";
            $request = KlassciHttpClient::decorateRequest(
                $pool->as((string) $id)->connectTimeout($this->connectTimeout)->timeout($this->timeout),
                $url,
                $this->sslVerify,
                $token,
            );
            $requests[] = $request->get($url);
        }

        return $requests;
    }

    private function classify(mixed $response): SeanceCheckResult
    {
        if (!$response instanceof Response) {
            // Exception de transport (timeout, connexion refusée) : Http::pool()
            // retourne l'exception elle-même à cette clé, jamais une Response.
            return SeanceCheckResult::Error;
        }

        if ($response->ok()) {
            return SeanceCheckResult::Exists;
        }

        if ($response->status() === 404) {
            return SeanceCheckResult::ConfirmedDeleted;
        }

        return SeanceCheckResult::Error;
    }
}
