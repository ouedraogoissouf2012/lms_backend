<?php

declare(strict_types=1);

namespace App\Services\Klassci;

use App\Exceptions\KlassciUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * PERF-02 (issue #137) — Client HTTP unifié pour KLASSCI.
 *
 * ## Avant l'éclatement (HIGH-2 audit `spec-architect`)
 *
 * `KlassciProxyService` avait 2 méthodes quasi-identiques :
 * - `makeRequest()` — utilisait `$this->token` (token système).
 * - `performUserTokenRequest()` — utilisait `$userToken` (param).
 *
 * ~45 lignes dupliquées (headers, SSL check, `match($method)`, gestion d'erreur).
 * Seule différence sémantique : source du token + suffixe de log `(User Token)`.
 *
 * ## Maintenant — méthode unique `executeHttp()`
 *
 * Le paramètre `?string $overrideToken = null` arbitre :
 * - `null` → utilise le token système via {@see KlassciConfigResolver::token()}.
 * - non-null → utilise le token utilisateur explicite.
 *
 * Comportement HTTP, headers, SSL, gestion d'erreur **strictement identiques** au
 * code pré-éclatement (extraits verbatim de `makeRequest` + `performUserTokenRequest`).
 *
 * ## DI
 *
 * Injecte `Http\Factory` (contract Laravel) au lieu d'utiliser le facade `Http::*`
 * — vraie inversion de dépendance, mockable via `$factory->fake(...)` en test.
 *
 * @see .claude/specs/perf-02-klassci-batch-cache/design.md §2-3
 */
final class KlassciHttpClient
{
    /**
     * Frontière 4xx/5xx : à partir de ce status, l'échec est une panne serveur
     * KLASSCI (error) ; en-dessous c'est une réponse client attendue (warning).
     */
    private const SERVER_ERROR_THRESHOLD = 500;

    private readonly int $timeout;

    private readonly int $connectTimeout;

    private readonly bool $sslVerify;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly KlassciConfigResolver $config,
        private readonly LoggerInterface $logger,
    ) {
        $this->connectTimeout = self::positiveIntConfig('services.klassci.connect_timeout', 2);
        $this->timeout = self::positiveIntConfig('services.klassci.timeout', 5);
        $this->sslVerify = (bool) config('services.klassci.ssl_verify', true);
    }

    /**
     * Exécute un appel HTTP vers KLASSCI.
     *
     * Si `$overrideToken` est null, utilise le token système résolu par
     * {@see KlassciConfigResolver} (priorité 3-tiers). Sinon utilise le token
     * utilisateur fourni explicitement.
     *
     * @param  array<string, mixed>  $data  Body pour POST/PUT, query string pour GET
     * @return array<string, mixed>
     *
     * @throws \App\Exceptions\KlassciUnavailableException si l'URL de base KLASSCI est absente/invalide (#270)
     * @throws ConnectionException si KLASSCI est injoignable (panne réseau/transport)
     * @throws \RuntimeException sur réponse HTTP 4xx/5xx
     * @throws \InvalidArgumentException si la méthode HTTP n'est pas supportée
     */
    public function executeHttp(
        string $method,
        string $endpoint,
        array $data = [],
        ?string $overrideToken = null,
    ): array {
        $token = $overrideToken ?? $this->config->token();
        // #270 — valide l'URL de base AVANT de construire la requête : sans scheme
        // http(s), Guzzle lèverait « The scheme '' is not allowed » rendu en 500.
        // requireBaseUrl() lève KlassciUnavailableException (→ 503) à la place.
        $baseUrl = $this->config->requireBaseUrl();
        $url = $baseUrl . '/' . ltrim($endpoint, '/');

        $logSuffix = $overrideToken !== null ? ' (User Token)' : '';

        $this->logger->info("KLASSCI API Request{$logSuffix}", [
            'method'         => $method,
            'url'            => $url,
            'params'         => $method === 'GET' ? $data : [],
            'has_user_token' => $overrideToken !== null && !empty($overrideToken),
        ]);

        $request = self::decorateRequest(
            $this->http
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout),
            $url,
            $this->sslVerify,
            $token,
        );

        // On isole UNIQUEMENT l'appel transport : une ConnectionException (DNS,
        // timeout, connexion refusée) est une panne serveur réelle → error. Les
        // codes HTTP d'échec (4xx/5xx) ne lèvent pas ici ; ils sont gérés via
        // failed() ci-dessous, à leur niveau de log propre (issue #256).
        try {
            $response = match ($method) {
                'GET'    => $request->get($url, $data),
                'POST'   => $request->post($url, $data),
                'PUT'    => $request->put($url, $data),
                'DELETE' => $request->delete($url),
                default  => throw new \InvalidArgumentException("Méthode HTTP non supportée: {$method}"),
            };
        } catch (ConnectionException $e) {
            $this->logger->error("KLASSCI API Exception{$logSuffix}", [
                'message'  => $e->getMessage(),
                'endpoint' => $endpoint,
            ]);

            throw $e;
        }

        if ($response->failed()) {
            $status = $response->status();

            // Un 4xx (403 autorisation, 404 introuvable, 429 throttle) est une
            // réponse ATTENDUE côté client, pas une panne : warning, jamais error.
            // Seul un 5xx (panne KLASSCI réelle) reste un error. Contexte réduit à
            // status + endpoint — le body n'est NI loggé NI exposé dans l'exception
            // (issue #256 : 239 Mo de logs sur des 403/429 répétés avec body complet).
            $level = $status >= self::SERVER_ERROR_THRESHOLD ? LogLevel::ERROR : LogLevel::WARNING;
            $this->logger->log($level, "KLASSCI API Error{$logSuffix}", [
                'status'   => $status,
                'endpoint' => $endpoint,
            ]);

            if ($status >= self::SERVER_ERROR_THRESHOLD) {
                throw KlassciUnavailableException::upstreamFailure($status);
            }

            throw new \RuntimeException("Erreur API KLASSCI: {$status}", $status);
        }

        $result = $response->json();
        if (!is_array($result)) {
            $result = [];
        }

        $this->logger->info("KLASSCI API Response{$logSuffix}", [
            'success' => $result['success'] ?? false,
        ]);

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * Décore un `PendingRequest` avec les headers KLASSCI standard, SSL conditionnel
     * et auth bearer si token non vide.
     *
     * Helper statique pur — appelé par `executeHttp()` ET par `KlassciBatchFetcher`
     * pour partager la même politique de décoration HTTP (DRY fix audit MEDIUM-2).
     * Pas d'état interne, donc statique acceptable malgré §1.6 D (pas un Service
     * Locator, juste une fonction pure sur un type d'entrée injecté).
     *
     * @param  PendingRequest  $request  Builder déjà initialisé (timeout appliqué en amont)
     * @param  string  $url               URL cible (utilisée pour le check SSL https-only)
     * @param  bool  $sslVerify           Doit-on vérifier SSL ? (false → withoutVerifying si https)
     * @param  string|null  $token        Bearer token ou null/'' pour ne pas en attacher
     */
    public static function decorateRequest(
        PendingRequest $request,
        string $url,
        bool $sslVerify,
        ?string $token,
    ): PendingRequest {
        $req = $request->withHeaders([
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ]);

        if (str_starts_with($url, 'https://') && !$sslVerify) {
            $req = $req->withoutVerifying();
        }

        if (is_string($token) && $token !== '') {
            $req = $req->withToken($token);
        }

        return $req;
    }

    private static function positiveIntConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
