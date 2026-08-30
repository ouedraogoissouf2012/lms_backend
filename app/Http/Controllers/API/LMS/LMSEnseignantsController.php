<?php

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\KlassciUnavailableException;
use App\Http\Controllers\AuthenticatedController;
use App\Models\LmsEnseignantCache;
use App\Services\KlassciProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * LMS Enseignants — liste enrichie depuis KLASSCI avec cache local.
 *
 * Extracted from `LMSDataController` as part of the god-object refactor
 * (spec: `.claude/specs/lms-data-controller-split/`).
 *
 * Responsibility:
 *   - GET /api/lms/enseignants    → getEnseignantsFromKlassci()
 *
 * Note on the cleanup of `getEnseignants()` and `getMatieresEnrichiesForEnseignant()`:
 * The legacy `LMSDataController` also had a public `getEnseignants()` method that
 * read directly from local DB tables (`esbtp_teachers`, etc.). Investigation
 * confirmed it had no route declaration in `routes/api.php` and no internal caller
 * — pure dead code. The only consumer of the local `getMatieresEnrichiesForEnseignant()`
 * helper was that dead `getEnseignants()` method. Both are removed in this PR.
 *
 * The active method `getEnseignantsFromKlassci()` reaches the upstream KLASSCI
 * API via `KlassciProxyService::getEnseignantsEnrichis()` and caches per-enseignant
 * via `LmsEnseignantCache::store()` (10-minute TTL).
 */
final class LMSEnseignantsController extends AuthenticatedController
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
    ) {}

    /**
     * GET /api/lms/enseignants
     * Retourne la liste des enseignants depuis KLASSCI externe avec cache local 10 min.
     *
     * Paramètres acceptés:
     *   - with_details (boolean): activer le format enrichi (classes/matières/stats)
     */
    public function getEnseignantsFromKlassci(Request $request): JsonResponse
    {
        try {
            // Authentification garantie par AuthenticatedController + auth:sanctum.
            // L'appel à `authenticatedUser()` enforce que la requête est typée même
            // si la méthode n'utilise pas directement $user (cohérence avec les
            // autres méthodes migrées du LMS split).
            $this->authenticatedUser($request);

            $startTime = microtime(true);
            $withDetails = $request->boolean('with_details', false);

            Log::info('[LMS Enseignants KLASSCI] Récupération depuis API externe', [
                'with_details' => $withDetails
            ]);

            // Appeler KLASSCI externe via le service
            $response = $this->klassciService->getEnseignantsEnrichis($withDetails);

            if (!isset($response['success']) || !$response['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $response['message'] ?? 'Erreur KLASSCI',
                    'data' => []
                ], 500);
            }

            /** @var array<int, array<string, mixed>> $enseignants */
            $enseignants = $response['data'] ?? [];

            if ($withDetails) {
                $this->warmEnseignantCache($enseignants);
            }

            return $this->successResponse($enseignants, '', 200, $this->buildMeta($response, $startTime));

        } catch (KlassciUnavailableException $e) {
            // Panne KLASSCI : 503 retryable, jamais le 500 generique ci-dessous.
            // Un 500 est definitif pour le client ; il transformerait une coupure
            // de quelques minutes en echec permanent. Reponse canonique unique,
            // partagee avec le handler global et le trait proxy.
            return KlassciUnavailableException::jsonResponse();
        } catch (\Exception $e) {
            // §1.2 — Détail technique loggé server-side, message générique au client.
            Log::error('[LMS Enseignants KLASSCI] Erreur', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des enseignants.',
                'data'    => [],
            ], 500);
        }
    }

    /**
     * Rechauffe le cache local des enseignants — opportuniste par nature : un
     * echec d'ecriture ne doit jamais faire echouer la requete qui, elle, a
     * deja obtenu sa donnee de KLASSCI.
     *
     * Extrait de {@see self::getEnseignantsFromKlassci()} : la mise en cache est
     * une responsabilite distincte de la reponse HTTP, et une methode longue
     * n'est pas testable unitairement (§5).
     *
     * @param  array<int, array<string, mixed>>  $enseignants
     */
    private function warmEnseignantCache(array $enseignants): void
    {
        foreach ($enseignants as $enseignant) {
            if (! isset($enseignant['id'])) {
                continue;
            }

            try {
                LmsEnseignantCache::store($enseignant['id'], $enseignant, 10);
            } catch (\Exception $cacheErr) {
                Log::warning('[LMS Enseignants KLASSCI] Cache store failed', [
                    'enseignant_id' => $enseignant['id'],
                    'error' => $cacheErr->getMessage(),
                ]);
            }
        }
    }

    /**
     * Fusionne le `meta` renvoye par KLASSCI avec les indicateurs propres au LMS.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function buildMeta(array $response, float $startTime): array
    {
        $klassciMeta = $response['meta'] ?? [];

        return array_merge(is_array($klassciMeta) ? $klassciMeta : [], [
            'source' => 'klassci_externe',
            'lms_cache_enabled' => true,
            'lms_performance' => [
                'total_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ],
        ]);
    }
}
