<?php

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Services\KlassciProxyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LMSMatieresAdminController — extrait verbatim de LMSMatieresController.
 * Refactor du god-controller (774 lignes -> 2 fichiers SRP).
 * Aucun changement comportemental.
 */
final class LMSMatieresAdminController extends AuthenticatedController
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
    ) {}

    /**
     * GET /api/admin/matieres
     * Liste toutes les matières avec leurs combinaisons complètes (pour admin/coordinateur).
     *
     * Retourne:
     * - Liste des matières enrichies avec combinaisons valides (filière + niveau)
     * - Statistiques globales
     */
    public function adminMatieresList(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $klassciToken = $user->klassci_token;

            if (!$klassciToken) {
                return $this->errorResponse('Token KLASSCI non trouvé. Veuillez vous reconnecter.', 401);
            }

            // Defense in depth: la route middleware `role:admin,coordinateur` filtre
            // déjà, mais on garde le check controller comme garde supplémentaire.
            // Note: `superAdmin` accepté car role intra-tenant admin (cf. EnsureRole.php).
            if (!($user->isCoordinator() || $user->isAdmin())) {
                return $this->errorResponse('Accès non autorisé. Réservé aux administrateurs et coordinateurs.', 403);
            }

            Log::info('Récupération liste matières admin', [
                'user_id' => $user->id,
                'role' => $user->role
            ]);

            // 1. Récupérer la liste des matières depuis l'endpoint /matieres
            $matieresResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'matieres',
                'GET'
            );

            /** @var array<int, array<string, mixed>> $matieres */
            $matieres = $matieresResponse['data'] ?? [];

            if (count($matieres) === 0) {
                return $this->successResponse([
                    'matieres' => [],
                    'statistiques' => [
                        'total' => 0,
                        'total_heures' => 0,
                        'total_seances' => 0,
                    ],
                ], 'Aucune matière trouvée');
            }

            Log::info('Matières trouvées', ['count' => count($matieres)]);

            // 2. Enrichir chaque matière avec ses combinaisons complètes
            $matieresEnrichies = [];

            foreach ($matieres as $matiere) {
                $matiereId = (int) $matiere['id'];

                try {
                    $detailsResponse = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        "matieres/{$matiereId}",
                        'GET'
                    );

                    $details = $detailsResponse['data'] ?? [];
                    $combinaisons = $details['combinaisons'] ?? [];

                    $matieresEnrichies[] = [
                        'id' => $matiere['id'],
                        'nom' => $matiere['nom'] ?? 'N/A',
                        'code' => $matiere['code'] ?? 'N/A',
                        'description' => $matiere['description'] ?? null,
                        'coefficient' => $matiere['coefficient'] ?? null,
                        'couleur' => $matiere['couleur'] ?? '#6366f1',
                        'heures_total' => $matiere['heures_total'] ?? 0,
                        'nb_seances_programmees' => $matiere['nb_seances_programmees'] ?? 0,
                        'nb_lecons' => $matiere['nb_lecons'] ?? 0,
                        'nb_evaluations' => $matiere['nb_evaluations'] ?? 0,
                        'combinaisons' => $combinaisons
                    ];

                    Log::info('Matière enrichie', [
                        'id' => $matiereId,
                        'nom' => $matiere['nom'],
                        'combinaisons_count' => count($combinaisons)
                    ]);

                } catch (\Exception $e) {
                    Log::warning('Erreur enrichissement matière', [
                        'matiere_id' => $matiereId,
                        'error' => $e->getMessage()
                    ]);

                    // En cas d'erreur, garder la matière avec combinaisons vides
                    $matieresEnrichies[] = [
                        'id' => $matiere['id'],
                        'nom' => $matiere['nom'] ?? 'N/A',
                        'code' => $matiere['code'] ?? 'N/A',
                        'description' => $matiere['description'] ?? null,
                        'coefficient' => $matiere['coefficient'] ?? null,
                        'couleur' => $matiere['couleur'] ?? '#6366f1',
                        'heures_total' => $matiere['heures_total'] ?? 0,
                        'nb_seances_programmees' => $matiere['nb_seances_programmees'] ?? 0,
                        'nb_lecons' => $matiere['nb_lecons'] ?? 0,
                        'nb_evaluations' => $matiere['nb_evaluations'] ?? 0,
                        'combinaisons' => []
                    ];
                }
            }

            // 3. Calculer les statistiques globales
            $totalHeures = array_sum(array_column($matieresEnrichies, 'heures_total'));
            $totalSeances = array_sum(array_column($matieresEnrichies, 'nb_seances_programmees'));

            $stats = [
                'total' => count($matieresEnrichies),
                'total_heures' => $totalHeures,
                'total_seances' => $totalSeances
            ];

            return $this->successResponse([
                'matieres' => $matieresEnrichies,
                'statistiques' => $stats,
            ], count($matieresEnrichies) . ' matière(s) récupérée(s)');

        } catch (\Exception $e) {
            Log::error('Erreur liste matières admin', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des matières',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }
}
