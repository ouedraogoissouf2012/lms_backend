<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Proxy;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Proxy KLASSCI — Lecture organisationnelle.
 *
 * Endpoints lecture seule (GET) qui exposent la structure, classes,
 * matières, enseignants, filières, niveaux et test de connexion.
 *
 * Extrait verbatim de ProxyController (395 lignes, 16 méthodes) splitté
 * en 3 controllers SRP par concern.
 *
 * @see PRODUCTION_STANDARDS.md §5 — Controllers ≤200 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict (constructor injection)
 */
final class ProxyOrganisationController extends Controller
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
    ) {
    }

    /**
     * GET /api/proxy/structure — Structure organisationnelle (filières, niveaux).
     */
    public function structure(): JsonResponse
    {
        try {
            $data = $this->klassciService->getStructure();
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse('Service indisponible. Veuillez réessayer.');
        }
    }

    /**
     * GET /api/proxy/classes — Liste des classes actives.
     */
    public function classes(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['filiere_id', 'niveau_id', 'annee_id']);
            $data = $this->klassciService->getClasses($filters);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse('Service indisponible. Veuillez réessayer.');
        }
    }

    /**
     * GET /api/proxy/classes/{id}/etudiants — Étudiants d'une classe.
     */
    public function etudiants(int $id, Request $request): JsonResponse
    {
        try {
            $anneeId = $request->input('annee_id');
            $data = $this->klassciService->getClasseEtudiants($id, $anneeId);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse('Service indisponible. Veuillez réessayer.');
        }
    }

    /**
     * GET /api/proxy/matieres — Matières.
     */
    public function matieres(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['filiere_id', 'niveau_id']);
            $data = $this->klassciService->getMatieres($filters);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse('Service indisponible. Veuillez réessayer.');
        }
    }

    /**
     * GET /api/proxy/matieres/{id} — Détails d'une matière.
     */
    public function matiereDetails(int $id): JsonResponse
    {
        try {
            $data = $this->klassciService->getMatiereDetails($id);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse('Service indisponible. Veuillez réessayer.');
        }
    }

    /**
     * GET /api/proxy/enseignants — Enseignants.
     *
     * WORKAROUND : KLASSCI retourne parfois 0 enseignants alors que la BDD
     * locale en contient. Si KLASSCI répond vide, on fallback sur la BDD
     * locale (rôles enseignant/teacher).
     */
    public function enseignants(): JsonResponse
    {
        try {
            $data = $this->klassciService->getEnseignants();

            if (empty($data['data'])) {
                Log::info('KLASSCI retourne 0 enseignants, utilisation BDD locale');

                $localEnseignants = User::whereIn('role', ['enseignant', 'teacher'])
                    ->get()
                    ->map(static function (User $user): array {
                        return [
                            'id' => $user->klassci_id ?? $user->id,
                            'nom' => $user->name,
                            'prenom' => '',
                            'email' => $user->email,
                            'role' => $user->role,
                            'source' => 'lms_local',
                        ];
                    })
                    ->toArray();

                return response()->json([
                    'success' => true,
                    'data' => $localEnseignants,
                    'meta' => [
                        'total' => count($localEnseignants),
                        'source' => 'lms_local',
                        'note' => 'Données provenant de la BDD locale car KLASSCI retourne 0',
                    ],
                ]);
            }

            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse('Service indisponible. Veuillez réessayer.');
        }
    }

    /**
     * GET /api/proxy/filieres — Filières.
     */
    public function filieres(): JsonResponse
    {
        try {
            $data = $this->klassciService->getFilieres();
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse('Service indisponible. Veuillez réessayer.');
        }
    }

    /**
     * GET /api/proxy/niveaux-etudes — Niveaux d'études.
     */
    public function niveauxEtudes(): JsonResponse
    {
        try {
            $data = $this->klassciService->getNiveauxEtudes();
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse('Service indisponible. Veuillez réessayer.');
        }
    }

    /**
     * GET /api/proxy/test-connection — Diagnostic connexion KLASSCI.
     */
    public function testConnection(): JsonResponse
    {
        try {
            $isConnected = $this->klassciService->testConnection();

            return response()->json([
                'success' => $isConnected,
                'message' => $isConnected
                    ? 'Connexion à l\'API KLASSCI réussie'
                    : 'Impossible de se connecter à l\'API KLASSCI',
                'api_url' => config('services.klassci.url'),
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Service indisponible. Veuillez réessayer.');
        }
    }

    /**
     * Réponse d'erreur standardisée.
     */
    private function errorResponse(string $message, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
