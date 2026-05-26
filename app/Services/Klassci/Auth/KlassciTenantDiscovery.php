<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

use App\Models\Institution;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;

/**
 * Découverte multi-tenant KLASSCI pour le login.
 *
 * ## Issue #120 — Extrait de `AuthController::findTenantsForUser()` (528 lignes → orchestrateur)
 *
 * Pour un `$identifier` (username ou email), interroge **tous les tenants KLASSCI
 * actifs** en parallèle via `Http::pool()`. Retourne la liste de ceux qui ont
 * trouvé l'utilisateur (`/auth/check-user` répond `data.found === true`).
 *
 * ## Fix bug pré-existant — `successful()` sur ConnectionException
 *
 * Bug origine : commit `a6066be2` (2026-03-21).
 *
 * `Http::pool()` peut retourner pour chaque tenant soit une `Response` (success
 * réseau, quel que soit le code HTTP), soit une `ConnectionException` (DNS down,
 * SSL invalide, timeout). Le code original appelait `->successful()` SANS vérifier
 * `instanceof Response` → crash 500 du login dès qu'un tenant est inaccessible.
 *
 * Ce service applique le pattern canonique du projet (déjà utilisé dans
 * {@see \App\Services\Klassci\KlassciBatchFetcher::persistBatchResponses()}
 * PR #137) : `if (!$response instanceof Response) { log + skip; }`.
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * Constructor injecte `HttpFactory` (contract Laravel) et `LoggerInterface`
 * (PSR-3) — pas de Facade en code métier. Mockable trivialement en test unit.
 *
 * @see app/Http/Controllers/API/AuthController.php (avant refactor)
 * @see .claude/specs/auth-controller-refactor/design.md §2.2
 */
final class KlassciTenantDiscovery
{
    private readonly bool $sslVerify;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly LoggerInterface $logger,
    ) {
        $this->sslVerify = (bool) config('services.klassci.ssl_verify', true);
    }

    /**
     * Interroge tous les tenants KLASSCI actifs en parallèle pour trouver
     * ceux qui connaissent `$identifier` (email ou username).
     *
     * @return array<int, array{code: string, api_base_url: string}>
     */
    public function findMatchingTenants(string $identifier): array
    {
        $tenants = $this->loadActiveTenants();
        if ($tenants === []) {
            return [];
        }

        $sslVerify = $this->sslVerify;
        $responses = $this->http->pool(function (Pool $pool) use ($tenants, $identifier, $sslVerify): array {
            $requests = [];
            foreach ($tenants as $tenant) {
                $request = $pool->as($tenant['code'])->timeout(10);
                if (!$sslVerify) {
                    $request = $request->withoutVerifying();
                }
                $requests[] = $request->post($tenant['api_base_url'] . '/auth/check-user', [
                    'identifier' => $identifier,
                ]);
            }

            return $requests;
        });

        $matching = $this->filterMatchingResponses($tenants, $responses);

        $this->logger->info('Tenants trouvés pour utilisateur', [
            'identifier' => $identifier,
            'count'      => count($matching),
            'tenants'    => array_column($matching, 'code'),
        ]);

        return $matching;
    }

    /**
     * Liste les tenants KLASSCI actifs depuis la table `institutions`.
     *
     * @return array<int, array{code: string, api_base_url: string}>
     */
    private function loadActiveTenants(): array
    {
        /** @var array<int, array{code: string, api_base_url: string}> $tenants */
        $tenants = Institution::where('is_active', true)
            ->whereNotNull('klassci_api_url')
            ->get()
            ->map(fn (Institution $inst): array => [
                'code'         => (string) $inst->slug,
                'api_base_url' => rtrim((string) $inst->klassci_api_url, '/'),
            ])
            ->values()
            ->all();

        return $tenants;
    }

    /**
     * Filtre les réponses pour ne garder que les tenants qui ont matché.
     *
     * **FIX BUG `successful()`** : vérifie `instanceof Response` AVANT d'appeler
     * les méthodes pour éviter le crash sur `ConnectionException`.
     *
     * @param  array<int, array{code: string, api_base_url: string}>  $tenants
     * @param  array<string, mixed>  $responses
     * @return array<int, array{code: string, api_base_url: string}>
     */
    private function filterMatchingResponses(array $tenants, array $responses): array
    {
        $matching = [];

        foreach ($tenants as $tenant) {
            $response = $responses[$tenant['code']] ?? null;

            if (!$response instanceof Response) {
                $reason = is_object($response) ? $response::class : 'null';
                $this->logger->warning('KLASSCI tenant unreachable during discovery', [
                    'tenant' => $tenant['code'],
                    'reason' => $reason,
                ]);
                continue;
            }

            if (!$response->successful()) {
                $this->logger->info('KLASSCI tenant check-user non-2xx', [
                    'tenant' => $tenant['code'],
                    'status' => $response->status(),
                ]);
                continue;
            }

            /** @var array<string, mixed>|mixed $data */
            $data = $response->json();
            if (!is_array($data)) {
                continue;
            }

            $payload = $data['data'] ?? null;
            if (is_array($payload) && (($payload['found'] ?? false) === true)) {
                $matching[] = $tenant;
            }
        }

        return $matching;
    }
}
