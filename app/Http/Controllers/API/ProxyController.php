<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\KlassciProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller Proxy pour l'API KLASSCI
 *
 * Ce controller expose les endpoints qui proxifient les appels vers l'API KLASSCI
 * avec gestion du cache et des erreurs
 */
class ProxyController extends Controller
{
    public function __construct(
        private KlassciProxyService $klassciService
    ) {}

    /**
     * GET /api/proxy/structure
     * Récupère la structure organisationnelle (filières, niveaux)
     */
    public function structure(): JsonResponse
    {
        try {
            $data = $this->klassciService->getStructure();
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * GET /api/proxy/classes
     * Récupère toutes les classes actives
     */
    public function classes(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['filiere_id', 'niveau_id', 'annee_id']);
            $data = $this->klassciService->getClasses($filters);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * GET /api/proxy/classes/{id}/etudiants
     * Récupère les étudiants d'une classe
     */
    public function etudiants(int $id, Request $request): JsonResponse
    {
        try {
            $anneeId = $request->input('annee_id');
            $data = $this->klassciService->getClasseEtudiants($id, $anneeId);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * GET /api/proxy/matieres
     * Récupère les matières
     */
    public function matieres(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['filiere_id', 'niveau_id']);
            $data = $this->klassciService->getMatieres($filters);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * GET /api/proxy/enseignants
     * Récupère les enseignants
     */
    public function enseignants(): JsonResponse
    {
        try {
            $data = $this->klassciService->getEnseignants();
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * GET /api/proxy/filieres
     * Récupère les filières
     */
    public function filieres(): JsonResponse
    {
        try {
            $data = $this->klassciService->getFilieres();
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * GET /api/proxy/niveaux-etudes
     * Récupère les niveaux d'études
     */
    public function niveauxEtudes(): JsonResponse
    {
        try {
            $data = $this->klassciService->getNiveauxEtudes();
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * GET /api/proxy/evaluations
     * Récupère les évaluations
     */
    public function evaluations(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['matiere_id', 'classe_id', 'statut']);
            $data = $this->klassciService->getEvaluations($filters);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * GET /api/proxy/emploi-temps
     * Récupère l'emploi du temps
     */
    public function emploiTemps(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['classe_id', 'enseignant_id', 'date_debut', 'date_fin']);
            $data = $this->klassciService->getEmploiTemps($filters);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * POST /api/proxy/evaluations/{id}/notes
     * Sauvegarde les notes d'une évaluation
     */
    public function saveNotes(int $id, Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'notes' => 'required|array',
                'notes.*.etudiant_id' => 'required|integer',
                'notes.*.note' => 'nullable|numeric|min:0|max:20',
                'notes.*.is_absent' => 'boolean',
                'notes.*.commentaire' => 'nullable|string',
            ]);

            $data = $this->klassciService->saveNotes($id, $validated['notes']);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * POST /api/proxy/cours/{id}/presences
     * Enregistre les présences d'un cours
     */
    public function savePresences(int $id, Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'date_cours' => 'required|date',
                'etudiants_presents' => 'required|array',
                'etudiants_absents' => 'nullable|array',
                'duree_effective_minutes' => 'nullable|integer',
                'commentaire' => 'nullable|string',
            ]);

            $presences = [
                'date_cours' => $validated['date_cours'],
                'etudiants_presents' => $validated['etudiants_presents'],
                'etudiants_absents' => $validated['etudiants_absents'] ?? [],
                'duree_effective_minutes' => $validated['duree_effective_minutes'] ?? null,
                'commentaire' => $validated['commentaire'] ?? null,
            ];

            $data = $this->klassciService->savePresences($id, $presences);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * PUT /api/proxy/cours/{id}/statut
     * Met à jour le statut d'un cours
     */
    public function updateCoursStatut(int $id, Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'statut' => 'required|string|in:en_cours,realise,annule',
                'commentaire' => 'nullable|string',
            ]);

            $data = $this->klassciService->updateCoursStatut(
                $id,
                $validated['statut'],
                $validated['commentaire'] ?? null
            );

            return response()->json($data);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * GET /api/proxy/me/dashboard
     * Récupère le dashboard de l'étudiant connecté (via token KLASSCI)
     *
     * Retourne : classe, cours (matières), quiz (évaluations), notes, statistiques
     */
    public function studentDashboard(Request $request): JsonResponse
    {
        try {
            // Récupérer le token depuis l'en-tête Authorization
            $authHeader = $request->header('Authorization');

            if (!$authHeader || strpos($authHeader, 'Bearer ') !== 0) {
                return $this->errorResponse('Token manquant', 401);
            }

            $userToken = substr($authHeader, 7); // Enlever "Bearer "

            if (!$userToken) {
                return $this->errorResponse('Token KLASSCI invalide', 401);
            }

            \Log::info('Dashboard request with user token', [
                'has_token' => !empty($userToken),
                'token_preview' => substr($userToken, 0, 10) . '...'
            ]);

            // Utiliser le token KLASSCI pour la requête
            $data = $this->klassciService->requestWithUserToken(
                $userToken,
                'me/dashboard',
                'GET'
            );

            return response()->json($data);
        } catch (\Exception $e) {
            \Log::error('Dashboard error', ['error' => $e->getMessage()]);
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * GET /api/proxy/me/teacher-dashboard
     * Récupère le dashboard de l'enseignant connecté (via token KLASSCI)
     *
     * Retourne : matieres, classes, evaluations, seances, statistiques
     */
    public function teacherDashboard(Request $request): JsonResponse
    {
        try {
            // Récupérer le token depuis l'en-tête Authorization
            $authHeader = $request->header('Authorization');

            if (!$authHeader || strpos($authHeader, 'Bearer ') !== 0) {
                return $this->errorResponse('Token manquant', 401);
            }

            $userToken = substr($authHeader, 7); // Enlever "Bearer "

            if (!$userToken) {
                return $this->errorResponse('Token KLASSCI invalide', 401);
            }

            \Log::info('Teacher Dashboard request with user token', [
                'has_token' => !empty($userToken),
                'token_preview' => substr($userToken, 0, 10) . '...'
            ]);

            // Utiliser le token KLASSCI pour la requête
            $data = $this->klassciService->requestWithUserToken(
                $userToken,
                'me/teacher-dashboard',
                'GET'
            );

            return response()->json($data);
        } catch (\Exception $e) {
            \Log::error('Teacher Dashboard error', ['error' => $e->getMessage()]);
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * GET /api/proxy/test-connection
     * Test de connexion à l'API KLASSCI
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
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Réponse d'erreur standardisée
     */
    private function errorResponse(string $message, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
