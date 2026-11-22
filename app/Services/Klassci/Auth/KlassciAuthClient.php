<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

use App\Exceptions\KlassciUnavailableException;
use App\Services\Klassci\KlassciBatchFetcher;
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
 * pattern que {@see KlassciBatchFetcher} (PR #137).
 *
 * ## `null` = refus CONCLUSIF, exception = réponse indéterminée (#687)
 *
 * `null` veut dire « KLASSCI a refusé ces identifiants » : un 4xx (hors 408 et
 * 429) ou un 2xx `success: false`. Une panne de transport, un 5xx, un 408, un 429
 * ou un corps illisible ne disent rien des identifiants : ils lèvent
 * {@see KlassciUnavailableException}. Les confondre faisait répondre « identifiants
 * incorrects » à un compte existant pendant une panne de KLASSCI.
 *
 * Le caller essaie chaque tenant trouvé ; il décide, une fois la boucle finie,
 * si un refus conclusif (401) ou une panne (503) l'emporte.
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
     * Statuts qui ne disent rien des identifiants : la requête n'a pas été jugée (#687).
     *
     * @var list<int>
     */
    private const STATUTS_SANS_JUGEMENT = [408];

    /**
     * Tente un login KLASSCI sur le tenant `$tenantUrl`.
     *
     * @param  string  $tenantUrl  URL de base du tenant (sans `/auth/login`)
     * @return array<string, mixed>|null Payload JSON sur succès, null sur refus conclusif
     *
     * @throws KlassciUnavailableException réponse indéterminée (#687)
     */
    public function attemptLogin(string $tenantUrl, string $username, string $password): ?array
    {
        $response = $this->sendLoginRequest($tenantUrl, $username, $password);

        return $this->parseLoginResponse($response, $tenantUrl);
    }

    /**
     * Envoie la requête HTTP login + catch ConnectionException (FIX BUG).
     *
     * @throws KlassciUnavailableException tenant injoignable (#687)
     */
    private function sendLoginRequest(string $tenantUrl, string $username, string $password): Response
    {
        try {
            return $this->buildRequest()->post($tenantUrl.'/auth/login', [
                'username' => $username,
                'password' => $password,
            ]);
        } catch (ConnectionException $e) {
            $this->logger->warning('KLASSCI tenant connection failed during login', [
                'tenant_url' => $tenantUrl,
                'error' => $e->getMessage(),
            ]);

            throw KlassciUnavailableException::transportFailure($e);
        }
    }

    /**
     * Le payload d'un succès, `null` pour un refus conclusif, une exception sinon (#687).
     *
     * @return array<string, mixed>|null
     *
     * @throws KlassciUnavailableException
     */
    private function parseLoginResponse(Response $response, string $tenantUrl): ?array
    {
        $status = $response->status();

        if (! $response->successful()) {
            $jugee = $status < 500 && ! in_array($status, self::STATUTS_SANS_JUGEMENT, true);
            $this->logger->info($jugee ? 'KLASSCI login refused' : 'KLASSCI login unavailable', [
                'tenant_url' => $tenantUrl,
                'status' => $status,
            ]);

            if (! $jugee) {
                throw KlassciUnavailableException::upstreamFailure($status);
            }

            return null;
        }

        /** @var array<string, mixed>|mixed $payload */
        $payload = $response->json();
        if (! is_array($payload)) {
            $this->logger->warning('KLASSCI login payload unreadable', ['tenant_url' => $tenantUrl]);

            throw KlassciUnavailableException::invalidPayload('auth/login');
        }

        if (($payload['success'] ?? false) !== true) {
            $this->logger->info('KLASSCI login refused (success=false)', ['tenant_url' => $tenantUrl]);

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
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);

        if (! $this->sslVerify) {
            $request = $request->withoutVerifying();
        }

        return $request;
    }
}
