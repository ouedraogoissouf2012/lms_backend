<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Proxy;

use App\Http\Controllers\API\Proxy\Concerns\RendersKlassciProxyErrors;
use App\Http\Controllers\API\Proxy\Concerns\ResolvesPersonalKlassciToken;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Proxy KLASSCI — Lecture organisationnelle (GET only).
 *
 * Structure, classes, matières, enseignants, filières, niveaux, test connexion.
 */
final class ProxyOrganisationController extends Controller
{
    use RendersKlassciProxyErrors;
    use ResolvesPersonalKlassciToken;

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
            return $this->proxyErrorResponse($e);
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
            return $this->proxyErrorResponse($e);
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
            return $this->proxyErrorResponse($e);
        }
    }

    /**
     * GET /api/proxy/matieres — Matières.
     */
    public function matieres(Request $request): JsonResponse
    {
        try {
            $klassciToken = $this->personalKlassciToken($request);
            if ($klassciToken === null) {
                return $this->missingKlassciTokenResponse();
            }

            $filters = $request->only(['filiere_id', 'niveau_id']);
            $data = $this->klassciService->getMatieres($klassciToken, $filters);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
        }
    }

    /**
     * GET /api/proxy/matieres/{id} — Détails d'une matière.
     */
    public function matiereDetails(int $id, Request $request): JsonResponse
    {
        try {
            $klassciToken = $this->personalKlassciToken($request);
            if ($klassciToken === null) {
                return $this->missingKlassciTokenResponse();
            }

            $data = $this->klassciService->getMatiereDetails($klassciToken, $id);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
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
            return $this->proxyErrorResponse($e);
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
            return $this->proxyErrorResponse($e);
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
            return $this->proxyErrorResponse($e);
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
            return $this->proxyErrorResponse($e);
        }
    }
}
