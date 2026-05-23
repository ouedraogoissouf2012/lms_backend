<?php

declare(strict_types=1);

namespace App\Services\Klassci;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Psr\Log\LoggerInterface;

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
    private readonly int $timeout;

    private readonly bool $sslVerify;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly KlassciConfigResolver $config,
        private readonly LoggerInterface $logger,
    ) {
        $timeoutConfig = config('services.klassci.timeout', 30);
        $this->timeout = is_int($timeoutConfig) ? $timeoutConfig : 30;
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
     * @throws \Exception sur HTTP 4xx/5xx ou méthode non supportée
     */
    public function executeHttp(
        string $method,
        string $endpoint,
        array $data = [],
        ?string $overrideToken = null,
    ): array {
        $token = $overrideToken ?? $this->config->token();
        $baseUrl = $this->config->baseUrl();
        $url = $baseUrl . '/' . ltrim($endpoint, '/');

        $logSuffix = $overrideToken !== null ? ' (User Token)' : '';

        try {
            $this->logger->info("KLASSCI API Request{$logSuffix}", [
                'method'         => $method,
                'url'            => $url,
                'params'         => $method === 'GET' ? $data : [],
                'has_user_token' => $overrideToken !== null && !empty($overrideToken),
            ]);

            $request = self::decorateRequest(
                $this->http->timeout($this->timeout),
                $url,
                $this->sslVerify,
                $token,
            );

            $response = match ($method) {
                'GET'    => $request->get($url, $data),
                'POST'   => $request->post($url, $data),
                'PUT'    => $request->put($url, $data),
                'DELETE' => $request->delete($url),
                default  => throw new \Exception("Méthode HTTP non supportée: {$method}"),
            };

            if ($response->failed()) {
                $this->logger->error("KLASSCI API Error{$logSuffix}", [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                throw new \Exception(
                    "Erreur API KLASSCI: " . $response->status() . " - " . $response->body()
                );
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
        } catch (\Exception $e) {
            $this->logger->error("KLASSCI API Exception{$logSuffix}", [
                'message'  => $e->getMessage(),
                'endpoint' => $endpoint,
            ]);

            throw $e;
        }
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
}
