<?php

namespace App\Http\Controllers\API\LMS;

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

            // Stocker en cache si format enrichi
            if ($withDetails && !empty($enseignants)) {
                foreach ($enseignants as $enseignant) {
                    if (isset($enseignant['id'])) {
                        try {
                            LmsEnseignantCache::store($enseignant['id'], $enseignant, 10);
                        } catch (\Exception $cacheErr) {
                            // Log silently — caching is opportunistic, don't fail the request
                            Log::warning('[LMS Enseignants KLASSCI] Cache store failed', [
                                'enseignant_id' => $enseignant['id'],
                                'error' => $cacheErr->getMessage(),
                            ]);
                        }
                    }
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            return response()->json([
                'success' => true,
                'data' => $enseignants,
                'meta' => array_merge($response['meta'] ?? [], [
                    'source' => 'klassci_externe',
                    'lms_cache_enabled' => true,
                    'lms_performance' => [
                        'total_time_ms' => $duration
                    ]
                ])
            ]);

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
}
