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
