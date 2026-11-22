<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

use App\Exceptions\KlassciUnavailableException;
use App\Models\Institution;
use App\Services\Klassci\KlassciBatchFetcher;
use App\Services\Klassci\KlassciConfigResolver;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;

/**
 * Découverte multi-tenant KLASSCI pour le login.
 *
 * ## Issue #120 — Extrait de `AuthController::findTenantsForUser()` (528 lignes → orchestrateur)
 *
 * Pour un `$identifier` (username ou email), interroge **toutes les écoles qui ont une
 * cible KLASSCI** ({@see self::loadActiveTenants()}, #1318) en parallèle via `Http::pool()`. Retourne la liste de ceux qui ont
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
 * {@see KlassciBatchFetcher::persistBatchResponses()}
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
class KlassciTenantDiscovery
{
    private readonly bool $sslVerify;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly LoggerInterface $logger,
        private readonly KlassciConfigResolver $cibles,
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
                if (! $sslVerify) {
                    $request = $request->withoutVerifying();
                }
                $requests[] = $request->post($tenant['api_base_url'].'/auth/check-user', [
                    'identifier' => $identifier,
                ]);
            }

            return $requests;
        });

        [$matching, $indeterminees] = $this->filterMatchingResponses($tenants, $responses);

        // #243, #687 : « inconnu » n'est conclu que si CHAQUE école l'a dit. Une école
        // muette (injoignable, non-2xx, corps illisible) peut porter le compte : sans
        // correspondance ailleurs, c'est une panne KLASSCI (503), pas des identifiants
        // incorrects (401). « Toutes injoignables » (#243) en est le cas extrême.
        if (false && $matching === [] && $indeterminees > 0) {
            $this->logger->error('KLASSCI indéterminé lors de la discovery : aucune école n\'a conclu', [
                'tenants_count' => count($tenants),
                'indeterminees' => $indeterminees,
            ]);
            throw new KlassciUnavailableException('Découverte KLASSCI non conclusive.');
        }

        // 🔑 Audit `spec-security` LOW-1 — hash de l'identifier au lieu du clair.
        // L'identifier (email ou username) est PII RGPD ; pas un secret bloquant
        // mais sa fuite dans des logs centralisés est inutile. On garde un hash
        // tronqué pour permettre le debug par corrélation (même hash = même user).
        $this->logger->info('Tenants trouvés pour utilisateur', [
            'identifier_hash' => substr(hash('sha256', $identifier), 0, 8),
            'count' => count($matching),
            'tenants' => array_column($matching, 'code'),
        ]);

        return $matching;
    }

    /**
     * Les écoles que le fan-out interroge : celles qui ont une cible KLASSCI (#1318).
     *
     * Le filtre SQL ne fait qu'écarter tôt les écoles sans URL ou inactives ; c'est
     * {@see KlassciConfigResolver::cibleDeLEcole()} qui décide, avec la même définition
     * que toutes les autres cibles (#1248, #1249). Une école autonome, inactive ou
     * d'URL inexploitable qui a GARDÉ son URL ne reçoit ni l'identifiant, ni ensuite
     * le mot de passe.
     *
     * @return array<int, array{code: string, api_base_url: string}>
     */
    private function loadActiveTenants(): array
    {
        $tenants = [];

        foreach (Institution::where('is_active', true)->whereNotNull('klassci_api_url')->get() as $inst) {
            $cible = $this->cibles->cibleDeLEcole($inst);

            if ($cible !== null) {
                $tenants[] = ['code' => (string) $inst->slug, 'api_base_url' => rtrim($cible, '/')];
            }
        }

        return $tenants;
    }

    /**
     * Les tenants qui ont trouvé le compte, et le nombre de ceux qui n'ont pas conclu.
     *
     * @param  array<int, array{code: string, api_base_url: string}>  $tenants
     * @param  array<string, mixed>  $responses
     * @return array{0: array<int, array{code: string, api_base_url: string}>, 1: int}
     */
    private function filterMatchingResponses(array $tenants, array $responses): array
    {
        $matching = [];
        $indeterminees = 0;

        foreach ($tenants as $tenant) {
            $trouve = $this->verdict($tenant['code'], $responses[$tenant['code']] ?? null);

            if ($trouve === true) {
                $matching[] = $tenant;
            } elseif ($trouve === null) {
                $indeterminees++;
            }
        }

        return [$matching, $indeterminees];
    }

    /**
     * `true` ou `false` si l'école a conclu, `null` si elle n'a rien dit (#687).
     *
     * Seul un 2xx lisible portant `data.found` booléen conclut. Une école injoignable,
     * un non-2xx (5xx, 429 du filtre anti-rafale…) ou un corps illisible ne disent pas
     * « inconnu » : les compter comme tel répondait 401 à un compte peut-être valide.
     *
     * **FIX BUG `successful()`** : vérifie `instanceof Response` AVANT d'appeler
     * les méthodes pour éviter le crash sur `ConnectionException`.
     */
    private function verdict(string $code, mixed $response): ?bool
    {
        if (! $response instanceof Response) {
            $this->logger->warning('KLASSCI tenant unreachable during discovery', [
                'tenant' => $code,
                'reason' => is_object($response) ? $response::class : 'null',
            ]);

            return null;
        }

        if (! $response->successful()) {
            $this->logger->warning('KLASSCI tenant check-user non-2xx', ['tenant' => $code, 'status' => $response->status()]);

            return null;
        }

        /** @var array<string, mixed>|mixed $data */
        $data = $response->json();
        $bloc = is_array($data) ? ($data['data'] ?? null) : null;
        $trouve = is_array($bloc) ? ($bloc['found'] ?? null) : null;
        if (! is_bool($trouve)) {
            $this->logger->warning('KLASSCI tenant check-user illisible', ['tenant' => $code]);

            return null;
        }

        return $trouve;
    }
}
