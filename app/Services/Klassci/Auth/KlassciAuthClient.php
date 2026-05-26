<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;

/**
 * Client HTTP dédié à l'authentification KLASSCI (POST /auth/login).
 *
 * ## Issue #120 — Extrait de `AuthController::login()` étape 2b (528 lignes → orchestrateur)
 *
 * Pour un tenant KLASSCI identifié (par {@see KlassciTenantDiscovery}), tente
 * un POST `/auth/login` avec les credentials. Retourne le payload JSON décodé
 * en cas de succès, `null` en cas d'échec (tenant down, 4xx, 5xx, payload
 * `success: false`).
 *
 * ## Fix bug pré-existant — `successful()` sur ConnectionException
 *
 * Bug origine : commit `a6066be2` (2026-03-21), ligne 172 de `AuthController.php`.
 *
 * `Http::post()` peut throw `ConnectionException` (DNS down, SSL invalide,
 * timeout). Le code original n'attrapait pas l'exception et appelait
 * `$loginResponse->successful()` SANS vérifier `instanceof Response` →
 * crash 500 du login.
 *
 * Ce service applique le pattern canonique du projet : try/catch
 * `ConnectionException` ET check `$response instanceof Response`. Même
 * pattern que {@see \App\Services\Klassci\KlassciBatchFetcher} (PR #137).
 *
 * ## Sémantique « null = échec silencieux »
 *
 * Le caller (`AuthController`) itère sur la liste des tenants matchés et
 * essaie chacun jusqu'à succès. `null` est nécessaire pour « continue la
 * boucle ». Throw serait incorrect (briserait le pattern try-next-tenant).
 *
 * @see app/Http/Controllers/API/AuthController.php (avant refactor) lignes 160-234
 * @see .claude/specs/auth-controller-refactor/design.md §2.3
 */
class KlassciAuthClient
{
    private readonly int $timeout;

    private readonly bool $sslVerify;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly LoggerInterface $logger,
    ) {
        $timeoutConfig = config('services.klassci.timeout', 30);
        $this->timeout = is_int($timeoutConfig) ? $timeoutConfig : 30;
        $this->sslVerify = (bool) config('services.klassci.ssl_verify', true);
    }

    /**
     * Tente un login KLASSCI sur le tenant `$tenantUrl`.
     *
     * @param  string  $tenantUrl  URL de base du tenant (sans `/auth/login`)
     * @return array<string, mixed>|null  Payload JSON sur succès, null sur échec
     */
    public function attemptLogin(string $tenantUrl, string $username, string $password): ?array
    {
        $response = $this->sendLoginRequest($tenantUrl, $username, $password);
        if ($response === null) {
            return null;
        }

        return $this->parseLoginResponse($response, $tenantUrl);
    }

    /**
     * Envoie la requête HTTP login + catch ConnectionException (FIX BUG).
     *
     * @return Response|null  null si tenant unreachable
     */
    private function sendLoginRequest(string $tenantUrl, string $username, string $password): ?Response
    {
        try {
            return $this->buildRequest()->post($tenantUrl . '/auth/login', [
                'username' => $username,
                'password' => $password,
            ]);
        } catch (ConnectionException $e) {
            $this->logger->warning('KLASSCI tenant connection failed during login', [
                'tenant_url' => $tenantUrl,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Parse la Response HTTP en payload validé.
     *
     * Trois checks séquentiels :
     * 1. `successful()` (HTTP 2xx) — sinon log info + null
     * 2. `is_array($payload)` — sinon log warning + null
     * 3. `success === true` — sinon log info + null
     *
     * @return array<string, mixed>|null
     */
    private function parseLoginResponse(Response $response, string $tenantUrl): ?array
    {
        if (!$response->successful()) {
            $this->logger->info('KLASSCI login refused or HTTP failure', [
                'tenant_url' => $tenantUrl,
                'status'     => $response->status(),
            ]);

            return null;
        }

        /** @var array<string, mixed>|mixed $payload */
        $payload = $response->json();
        if (!is_array($payload) || ($payload['success'] ?? false) !== true) {
            $this->logger->info('KLASSCI login payload invalid or success=false', [
                'tenant_url' => $tenantUrl,
            ]);

            return null;
        }

        return $payload;
    }

    /**
     * Construit la requête HTTP avec les conventions du projet
     * (timeout configurable, SSL verify conditionnel, headers standard).
     */
    private function buildRequest(): PendingRequest
    {
        $request = $this->http->timeout($this->timeout)
            ->withHeaders([
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ]);

        if (!$this->sslVerify) {
            $request = $request->withoutVerifying();
        }

        return $request;
    }
}
