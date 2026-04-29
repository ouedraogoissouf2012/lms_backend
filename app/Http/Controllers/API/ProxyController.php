<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesApiExceptions;
use App\Models\Classe;
use App\Services\KlassciProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller Proxy pour l'API KLASSCI
 *
 * Ce controller expose les endpoints qui proxifient les appels vers l'API KLASSCI
 * avec gestion du cache et des erreurs
 *
 * PRODUCTION PATTERN:
 * - Utilise CustomException classes pour les erreurs attendues
 * - Exception Handler central gère les logs et réponses standardisées
 * - Tous les getMessage() sont supprimés, uniquement des codes d'erreur
 */
class ProxyController extends Controller
{
    use HandlesApiExceptions;

    public function __construct(
        private KlassciProxyService $klassciService
    ) {}

    /**
     * GET /api/proxy/structure
     * Récupère la structure organisationnelle (filières, niveaux)
     */
    public function structure(): JsonResponse
    {
        $data = $this->klassciService->getStructure();
        return response()->json($data);
    }

    /**
     * GET /api/proxy/classes
     * Récupère toutes les classes actives
     */
    public function classes(Request $request): JsonResponse
    {
        $filters = $request->only(['filiere_id', 'niveau_id', 'annee_id']);
        $data = $this->klassciService->getClasses($filters);
        return response()->json($data);
    }

    /**
     * GET /api/proxy/classes/{id}
     * Récupère les détails d'une classe
     */
    public function classeDetails(int $id): JsonResponse
    {
        $classe = Classe::findOrFail($id);
        return response()->json([
            'success' => true,
            'data' => $classe,
        ]);
    }

    /**
     * GET /api/proxy/classes/{id}/etudiants
     * Récupère les étudiants d'une classe
     */
    public function etudiants(int $id, Request $request): JsonResponse
    {
        $anneeId = $request->input('annee_id');
        $data = $this->klassciService->getClasseEtudiants($id, $anneeId);
        return response()->json($data);
    }

    /**
     * GET /api/proxy/matieres
     * Récupère les matières
     */
    public function matieres(Request $request): JsonResponse
    {
        $filters = $request->only(['filiere_id', 'niveau_id']);
        $data = $this->klassciService->getMatieres($filters);
        return response()->json($data);
    }

    /**
     * GET /api/proxy/matieres/{id}
     * Récupère les détails d'une matière
     */
    public function matiereDetails(int $id): JsonResponse
    {
        $data = $this->klassciService->getMatiereDetails($id);
        return response()->json($data);
    }

    /**
     * GET /api/proxy/enseignants
     * Récupère les enseignants
     *
     * WORKAROUND: KLASSCI retourne 0 enseignants via son API mais il y en a dans le LMS local
     * Solution: Retourner les enseignants depuis la BDD locale au lieu de KLASSCI
     */
    public function enseignants(): JsonResponse
    {
        $data = $this->klassciService->getEnseignants();

        if (empty($data['data'])) {
            \Log::info('KLASSCI retourne 0 enseignants, utilisation BDD locale');

            $localEnseignants = \App\Models\User::whereIn('role', ['enseignant', 'teacher'])
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->klassci_id ?? $user->id,
                        'nom' => $user->name,
                        'prenom' => '',
                        'email' => $user->email,
                        'role' => $user->role,
                        'source' => 'lms_local'
                    ];
                })
                ->toArray();

            return response()->json([
                'success' => true,
                'data' => $localEnseignants,
                'meta' => [
                    'total' => count($localEnseignants),
                    'source' => 'lms_local',
                    'note' => 'Données provenant de la BDD locale car KLASSCI retourne 0'
                ]
            ]);
        }

        return response()->json($data);
    }

    /**
     * GET /api/proxy/filieres
     * Récupère les filières
     */
    public function filieres(): JsonResponse
    {
        $data = $this->klassciService->getFilieres();
        return response()->json($data);
    }

    /**
     * GET /api/proxy/niveaux-etudes
     * Récupère les niveaux d'études
     */
    public function niveauxEtudes(): JsonResponse
    {
        $data = $this->klassciService->getNiveauxEtudes();
        return response()->json($data);
    }

    /**
     * GET /api/proxy/evaluations
     * Récupère les évaluations
     */
    public function evaluations(Request $request): JsonResponse
    {
        $filters = $request->only(['matiere_id', 'classe_id', 'statut']);
        $data = $this->klassciService->getEvaluations($filters);
        return response()->json($data);
    }

    /**
     * GET /api/proxy/emploi-temps
     * Récupère l'emploi du temps
     */
    public function emploiTemps(Request $request): JsonResponse
    {
        $filters = $request->only(['classe_id', 'enseignant_id', 'date_debut', 'date_fin']);
        $data = $this->klassciService->getEmploiTemps($filters);
        return response()->json($data);
    }

    /**
     * POST /api/proxy/evaluations/{id}/notes
     * Sauvegarde les notes d'une évaluation
     */
    public function saveNotes(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'required|array',
            'notes.*.etudiant_id' => 'required|integer',
            'notes.*.note' => 'nullable|numeric|min:0|max:20',
            'notes.*.is_absent' => 'boolean',
            'notes.*.commentaire' => 'nullable|string',
        ]);

        $data = $this->klassciService->saveNotes($id, $validated['notes']);
        return response()->json($data);
    }

    /**
     * POST /api/proxy/cours/{id}/presences
     * Enregistre les présences d'un cours
     */
    public function savePresences(int $id, Request $request): JsonResponse
    {
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
    }

    /**
     * PUT /api/proxy/cours/{id}/statut
     * Met à jour le statut d'un cours
     */
    public function updateCoursStatut(int $id, Request $request): JsonResponse
    {
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
    }

    /**
     * GET /api/proxy/me/dashboard
     * Récupère le dashboard de l'étudiant connecté (via token KLASSCI)
     *
     * Retourne : classe, cours (matières), quiz (évaluations), notes, statistiques
     */
    public function studentDashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            $this->unauthenticated(
                'Vous devez être authentifié',
                'Unauthenticated request to studentDashboard'
            );
        }

        $klassciToken = $user->klassci_token;

        if (!$klassciToken) {
            $this->unauthenticated(
                'Token KLASSCI non trouvé. Veuillez vous reconnecter.',
                'Missing KLASSCI token for student dashboard',
                ['user_id' => $user->id]
            );
        }

        \Log::info('Student Dashboard request', [
            'user_id' => $user->id,
            'klassci_id' => $user->klassci_id,
            'has_klassci_token' => !empty($klassciToken),
            'token_preview' => substr($klassciToken, 0, 10) . '...'
        ]);

        $data = $this->klassciService->requestWithUserToken(
            $klassciToken,
            'me/dashboard',
            'GET'
        );

        return response()->json($data);
    }

    /**
     * GET /api/proxy/me/teacher-dashboard
     * Récupère le dashboard de l'enseignant connecté (via token KLASSCI)
     *
     * Retourne : matieres, classes, evaluations, seances, statistiques
     */
    public function teacherDashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            $this->unauthenticated(
                'Vous devez être authentifié',
                'Unauthenticated request to teacherDashboard'
            );
        }

        $klassciToken = $user->klassci_token;

        if (!$klassciToken) {
            $this->unauthenticated(
                'Token KLASSCI non trouvé. Veuillez vous reconnecter.',
                'Missing KLASSCI token for teacher dashboard',
                ['user_id' => $user->id]
            );
        }

        \Log::info('Teacher Dashboard request', [
            'user_id' => $user->id,
            'klassci_id' => $user->klassci_id,
            'has_klassci_token' => !empty($klassciToken),
            'token_preview' => substr($klassciToken, 0, 10) . '...'
        ]);

        $data = $this->klassciService->requestWithUserToken(
            $klassciToken,
            'me/teacher-dashboard',
            'GET'
        );

        return response()->json($data);
    }

    /**
     * GET /api/proxy/test-connection
     * Test de connexion à l'API KLASSCI
     */
    public function testConnection(): JsonResponse
    {
        $isConnected = $this->klassciService->testConnection();

        return response()->json([
            'success' => $isConnected,
            'message' => $isConnected
                ? 'Connexion à l\'API KLASSCI réussie'
                : 'Impossible de se connecter à l\'API KLASSCI',
            'api_url' => config('services.klassci.url'),
        ]);
    }

}
