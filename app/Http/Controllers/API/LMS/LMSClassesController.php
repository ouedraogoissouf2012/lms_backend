<?php

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Services\KlassciProxyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * LMS Classes — détails et étudiants.
 *
 * Extracted from `LMSDataController` as part of the god-object refactor
 * (spec: `.claude/specs/lms-data-controller-split/`).
 *
 * Responsibilities:
 *   - GET /api/lms/classes/{classeId}              → classeDetails()
 *   - GET /api/lms/classes/{classeId}/etudiants    → classeEtudiants()
 *
 * Both endpoints proxy to the upstream KLASSCI API using the caller's
 * token, then enrich/filter the response.
 */
final class LMSClassesController extends AuthenticatedController
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
    ) {}

    /**
     * GET /api/lms/classes/{id}
     * Retourne les détails complets d'une classe.
     *
     * Contenu retourné:
     * - Informations classe (nom, filière, niveau, places)
     * - Liste complète des étudiants inscrits actifs
     * - Matières disponibles (via combinaison filière+niveau)
     * - Emploi du temps de la semaine courante
     * - Évaluations programmées
     * - Statistiques (taux présence, moyenne classe)
     */
    public function classeDetails(int $classeId, Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $klassciToken = $user->klassci_token;

            if (!$klassciToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.'
                ], 401);
            }

            Log::info('Récupération détails classe', [
                'classe_id' => $classeId,
                'user_id' => $user->id
            ]);

            // 1. Récupérer les informations de base de la classe avec ses relations
            try {
                $classeResponse = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "classes/{$classeId}?with=filiere,niveau",
                    'GET'
                );

                $classe = $classeResponse['data'] ?? null;

                Log::info('Classe récupérée depuis KLASSCI', [
                    'classe_id' => $classeId,
                    'has_filiere' => isset($classe['filiere']),
                    'filiere_id' => $classe['filiere']['id'] ?? 'N/A',
                    'has_niveau' => isset($classe['niveau']),
                    'niveau_id' => $classe['niveau']['id'] ?? 'N/A',
                    'classe_data' => $classe
                ]);

                if (!$classe) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Classe non trouvée'
                    ], 404);
                }
            } catch (\Exception $e) {
                Log::error('Erreur récupération classe', [
                    'classe_id' => $classeId,
                    'error' => $e->getMessage()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors de la récupération de la classe'
                ], 500);
            }

            // 2. Récupérer les étudiants de la classe
            $etudiantsResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "classes/{$classeId}/etudiants",
                'GET'
            );

            /** @var array<int, array<string, mixed>> $etudiants */
            $etudiants = $etudiantsResponse['data'] ?? [];

            // Filtrer uniquement les étudiants actifs
            $etudiantsActifs = collect($etudiants)->filter(function (array $etudiant): bool {
                return isset($etudiant['statut']) && $etudiant['statut'] === 'actif';
            })->values();

            // 3. Récupérer l'emploi du temps de la semaine courante
            $startOfWeek = Carbon::now()->startOfWeek()->format('Y-m-d');
            $endOfWeek = Carbon::now()->endOfWeek()->format('Y-m-d');

            try {
                $emploiTempsResponse = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "emploi-temps?classe_id={$classeId}&date_debut={$startOfWeek}&date_fin={$endOfWeek}",
                    'GET'
                );
                $emploiTemps = $emploiTempsResponse['data'] ?? [];
            } catch (\Exception $e) {
                Log::warning('Erreur récupération emploi du temps', [
                    'classe_id' => $classeId,
                    'error' => $e->getMessage()
                ]);
                $emploiTemps = [];
            }

            // 4. Récupérer les évaluations programmées pour cette classe
            try {
                $evaluationsResponse = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    'evaluations',
                    'GET'
                );

                /** @var array<int, array<string, mixed>> $evaluationsData */
                $evaluationsData = $evaluationsResponse['data'] ?? [];
                // Fix on extraction: KLASSCI can return `classe` as null or as a scalar
                // (observed in legacy data), not always as an array. Original code at
                // LMSDataController.php:150-151 dereferenced `$eval['classe']['id']` blindly
                // and would have crashed on malformed payloads. We guard with is_array.
                $evaluations = collect($evaluationsData)->filter(function (array $eval) use ($classeId): bool {
                    $classeData = $eval['classe'] ?? null;
                    return is_array($classeData) && isset($classeData['id']) && $classeData['id'] === $classeId;
                })->values();
            } catch (\Exception $e) {
                Log::warning('Erreur récupération évaluations', [
                    'classe_id' => $classeId,
                    'error' => $e->getMessage()
                ]);
                $evaluations = [];
            }

            // 5. Récupérer toutes les matières disponibles pour cette combinaison filière+niveau
            $matieres = [];
            if (isset($classe['filiere']['id']) && isset($classe['niveau']['id'])) {
                try {
                    $url = "matieres?filiere_id={$classe['filiere']['id']}&niveau_id={$classe['niveau']['id']}";
                    Log::info('Requête matières KLASSCI', [
                        'url' => $url,
                        'filiere_id' => $classe['filiere']['id'],
                        'niveau_id' => $classe['niveau']['id']
                    ]);

                    $matieresResponse = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        $url,
                        'GET'
                    );
                    $matieres = $matieresResponse['data'] ?? [];

                    Log::info('Matières récupérées depuis KLASSCI', [
                        'count' => count($matieres),
                        'matieres' => $matieres
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Erreur récupération matières', [
                        'classe_id' => $classeId,
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                Log::warning('Impossible de récupérer les matières - filière ou niveau manquant', [
                    'classe_id' => $classeId,
                    'has_filiere' => isset($classe['filiere']),
                    'has_niveau' => isset($classe['niveau'])
                ]);
            }

            // 6. Calculer des statistiques (si disponibles)
            $stats = [
                'nombre_etudiants' => count($etudiantsActifs),
                'nombre_seances_semaine' => count($emploiTemps),
                'nombre_evaluations_programmees' => count($evaluations),
                'nombre_matieres' => count($matieres),
                'capacite_classe' => $classe['nombre_places'] ?? null,
                'taux_remplissage' => isset($classe['nombre_places']) && $classe['nombre_places'] > 0
                    ? round((count($etudiantsActifs) / $classe['nombre_places']) * 100, 2)
                    : null
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'classe' => $classe,
                    'etudiants' => $etudiantsActifs,
                    'matieres_disponibles' => $matieres,
                    'emploi_temps_semaine' => $emploiTemps,
                    'evaluations_programmees' => $evaluations,
                    'statistiques' => $stats
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération détails classe', [
                'classe_id' => $classeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des détails de la classe',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/classes/{id}/etudiants
     * Retourne la liste des étudiants d'une classe.
     */
    public function classeEtudiants(int $classeId, Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $klassciToken = $user->klassci_token;

            if (!$klassciToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.'
                ], 401);
            }

            Log::info('Récupération étudiants classe', [
                'classe_id' => $classeId,
                'user_id' => $user->id
            ]);

            // Récupérer les étudiants via KLASSCI
            $etudiantsResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "classes/{$classeId}/etudiants",
                'GET'
            );

            /** @var array<int, array<string, mixed>> $etudiants */
            $etudiants = $etudiantsResponse['data'] ?? [];

            // Filter is intentionally permissive here vs. classeDetails():
            //   - classeDetails()  → strict `isset && === 'actif'`  (excludes legacy rows
            //                        without a `statut` field, used for display stats).
            //   - classeEtudiants() → permissive `!isset || === 'actif'` (includes legacy
            //                        rows, used as the canonical roster endpoint where
            //                        missing-status is treated as active by default).
            // Both behaviors are preserved verbatim from the original LMSDataController.
            $etudiantsActifs = collect($etudiants)->filter(function (array $etudiant): bool {
                return !isset($etudiant['statut']) || $etudiant['statut'] === 'actif';
            })->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'etudiants' => $etudiantsActifs,
                    'total' => count($etudiantsActifs)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération étudiants classe', [
                'classe_id' => $classeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des étudiants',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }
}
