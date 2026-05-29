<?php

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\DeleteSeanceRequest;
use App\Http\Requests\HideSeanceRequest;
use App\Http\Requests\ToggleVisioSeanceRequest;
use App\Http\Requests\UnhideSeanceRequest;
use App\Http\Requests\ValidateParticipantRequest;
use App\Services\KlassciProxyService;
use App\Services\SeanceQueryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * LMSSeancesMutationController — extrait verbatim de LMSSeancesController.
 * Refactor du god-controller (1524 lignes -> 2 fichiers SRP).
 * Aucun changement comportemental.
 */
final class LMSSeancesMutationController extends AuthenticatedController
{
    use \App\Http\Controllers\API\LMS\Concerns\FetchesSeanceDataFromKlassci;

    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly SeanceQueryService $seanceQuery,
    ) {}

    /**
     * POST /api/lms/seances/{id}/validate-participant
     */
    public function validateParticipant(int $seanceId, \App\Http\Requests\ValidateParticipantRequest $request): JsonResponse
    {
        try {
            $userId = $request->validated('user_id');
            $currentUser = $this->authenticatedUser($request);
            $klassciToken = $currentUser->klassci_token;

            $userToValidate = \App\Models\User::find($userId);

            if (!$userToValidate instanceof \App\Models\User) {
                return response()->json([
                    'success' => false,
                    'authorized' => false,
                    'reason' => 'user_not_found'
                ], 404);
            }

            Log::info('Validation participant séance', [
                'seance_id' => $seanceId,
                'user_id' => $userId,
                'user_role' => $userToValidate->role
            ]);

            // Vérifier d'abord si la visio existe et est active
            $visioData = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();

            Log::info('DEBUG validateParticipant - Données visio', [
                'seance_id' => $seanceId,
                'visio_found' => $visioData ? 'oui' : 'non',
                'visio_enabled' => $visioData?->visio_enabled,
                'visio_status' => $visioData?->visio_status,
                'klassci_matiere_id' => $visioData?->klassci_matiere_id
            ]);

            if (!$visioData || !$visioData->visio_enabled) {
                return response()->json([
                    'success' => false,
                    'authorized' => false,
                    'reason' => 'visio_not_enabled',
                    'message' => 'Visioconférence non activée pour cette séance'
                ], 403);
            }

            // Vérifier que la visio est active ou programmée
            if (!in_array($visioData->visio_status, ['active', 'programmee'])) {
                return response()->json([
                    'success' => false,
                    'authorized' => false,
                    'reason' => 'visio_not_started',
                    'message' => 'La visioconférence n\'a pas encore démarré'
                ], 403);
            }

            // Pour les enseignants/coordinateurs: autoriser directement
            if ($userToValidate->isTeacher() || $userToValidate->isCoordinator() || $userToValidate->isAdmin()) {
                Log::info('DEBUG validateParticipant - Enseignant autorisé', [
                    'user_id' => $userId,
                    'role' => $userToValidate->role
                ]);

                return response()->json([
                    'success' => true,
                    'authorized' => true,
                    'role' => ($userToValidate->isCoordinator() || $userToValidate->isAdmin()) ? 'moderator' : 'teacher',
                    'message' => 'Enseignant ou coordinateur autorisé'
                ]);
            }

            // Pour un étudiant: vérifier l'inscription dans la classe
            if ($userToValidate->isStudent()) {
                Log::info('DEBUG validateParticipant - Vérification étudiant', [
                    'user_id' => $userId,
                    'user_email' => $userToValidate->email,
                    'seance_id' => $seanceId,
                    'matiere_id' => $visioData->klassci_matiere_id,
                    'classe_id' => $visioData->klassci_classe_id
                ]);

                // WORKAROUND: On utilise /matieres/{id} si disponible, sinon on utilise directement classe_id de la BDD
                try {
                    $klassciUrl = env('KLASSCI_API_URL', 'https://presentation.klassci.com/api/lms');
                    $classeId = null;

                    // Stratégie 1: Si on a déjà classe_id dans la BDD locale, l'utiliser directement
                    if ($visioData->klassci_classe_id) {
                        $classeId = $visioData->klassci_classe_id;

                        Log::info('DEBUG validateParticipant - Utilisation classe_id de la BDD locale', [
                            'classe_id' => $classeId
                        ]);
                    }
                    // Stratégie 2: Sinon, chercher via /matieres/{id}
                    else if ($visioData->klassci_matiere_id) {
                        $matiereId = $visioData->klassci_matiere_id;

                        Log::info('DEBUG validateParticipant - Recherche via /matieres', [
                            'matiere_id' => $matiereId
                        ]);

                        // Appel API KLASSCI pour récupérer les infos de la matière
                        $httpClient = Http::timeout(30);
                        if (app()->environment('local')) {
                            $httpClient = $httpClient->withoutVerifying();
                        }
                        $matiereResponse = $httpClient->get("{$klassciUrl}/matieres/{$matiereId}");

                        Log::info('DEBUG validateParticipant - Réponse /matieres', [
                            'status' => $matiereResponse->status(),
                            'success' => $matiereResponse->successful()
                        ]);

                        if (!$matiereResponse->successful()) {
                            Log::error('DEBUG validateParticipant - Erreur API matieres', [
                                'status' => $matiereResponse->status(),
                                'body' => $matiereResponse->body()
                            ]);

                            return response()->json([
                                'success' => false,
                                'authorized' => false,
                                'reason' => 'klassci_api_error',
                                'message' => 'Erreur lors de la vérification des inscriptions'
                            ], 500);
                        }

                        $matiereData = $matiereResponse->json();
                        $seancesProgrammees = $matiereData['data']['seances_programmees'] ?? [];

                        Log::info('DEBUG validateParticipant - Séances programmées', [
                            'count' => count($seancesProgrammees),
                            'recherche_seance_id' => $seanceId
                        ]);

                        // Trouver la séance correspondante pour récupérer classe_id
                        $seanceInfo = collect($seancesProgrammees)->firstWhere('id', $seanceId);

                        if (!$seanceInfo) {
                            Log::warning('DEBUG validateParticipant - Séance non trouvée dans les programmations', [
                                'seance_id' => $seanceId,
                                'seances_disponibles' => collect($seancesProgrammees)->pluck('id')->toArray()
                            ]);

                            return response()->json([
                                'success' => false,
                                'authorized' => false,
                                'reason' => 'seance_not_found',
                                'message' => 'Séance non trouvée dans les programmations'
                            ], 403);
                        }

                        $classeId = $seanceInfo['classe_id'] ?? null;

                        Log::info('DEBUG validateParticipant - Séance trouvée via /matieres', [
                            'seance_id' => $seanceId,
                            'classe_id' => $classeId
                        ]);
                    }

                    // Si on n'a toujours pas de classe_id, erreur
                    if (!$classeId) {
                        Log::error('DEBUG validateParticipant - Pas de classe_id disponible', [
                            'seance_id' => $seanceId,
                            'has_matiere_id' => $visioData->klassci_matiere_id ? 'oui' : 'non',
                            'has_classe_id' => $visioData->klassci_classe_id ? 'oui' : 'non'
                        ]);

                        return response()->json([
                            'success' => false,
                            'authorized' => false,
                            'reason' => 'no_classe_id',
                            'message' => 'Classe non définie pour cette séance'
                        ], 403);
                    }

                    // Récupérer la liste des étudiants inscrits dans la classe
                    $httpClient2 = Http::timeout(30);
                    if (app()->environment('local')) {
                        $httpClient2 = $httpClient2->withoutVerifying();
                    }
                    $classesResponse = $httpClient2->get("{$klassciUrl}/classes/{$classeId}/etudiants");

                    Log::info('DEBUG validateParticipant - Réponse /classes/etudiants', [
                        'status' => $classesResponse->status(),
                        'success' => $classesResponse->successful()
                    ]);

                    if (!$classesResponse->successful()) {
                        Log::error('DEBUG validateParticipant - Erreur API classes/etudiants', [
                            'status' => $classesResponse->status(),
                            'body' => $classesResponse->body()
                        ]);

                        return response()->json([
                            'success' => false,
                            'authorized' => false,
                            'reason' => 'klassci_api_error',
                            'message' => 'Erreur lors de la vérification des inscriptions'
                        ], 500);
                    }

                    $classesData = $classesResponse->json();
                    $enrolledStudents = $classesData['data'] ?? [];

                    Log::info('DEBUG validateParticipant - Étudiants inscrits', [
                        'count' => count($enrolledStudents),
                        'emails' => collect($enrolledStudents)->pluck('email')->toArray()
                    ]);

                    // Étape 4: Vérifier si l'étudiant est inscrit dans la classe
                    // On compare par email car c'est l'identifiant unique
                    $isEnrolled = collect($enrolledStudents)->contains('email', $userToValidate->email);

                    Log::info('DEBUG validateParticipant - Résultat vérification inscription', [
                        'user_email' => $userToValidate->email,
                        'classe_id' => $classeId,
                        'is_enrolled' => $isEnrolled
                    ]);

                    if ($isEnrolled) {
                        return response()->json([
                            'success' => true,
                            'authorized' => true,
                            'role' => 'student',
                            'message' => 'Étudiant inscrit dans la classe - accès autorisé'
                        ]);
                    } else {
                        return response()->json([
                            'success' => false,
                            'authorized' => false,
                            'reason' => 'not_enrolled',
                            'message' => 'Vous n\'êtes pas inscrit dans cette classe'
                        ], 403);
                    }

                } catch (\Exception $e) {
                    // §1.2 — Détail technique loggé server-side, message générique au client.
                    Log::error('DEBUG validateParticipant - Exception lors de la vérification', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    return response()->json([
                        'success'    => false,
                        'authorized' => false,
                        'reason'     => 'verification_error',
                        'message'    => 'Erreur lors de la vérification de l\'inscription.',
                    ], 500);
                }
            }

            return response()->json([
                'success' => true,
                'authorized' => false,
                'reason' => 'invalid_role',
                'user_role' => $userToValidate->role
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur validation participant', [
                'seance_id' => $seanceId,
                'user_id' => $request->input('user_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la validation du participant',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/attendances/from-video-session
     */
    public function toggleVisioSeance(int $seanceId, \App\Http\Requests\ToggleVisioSeanceRequest $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $enabled = $request->validated('enabled');
            $visioType = $request->validated('visio_type') ?? 'jitsi';
            $klassciToken = $user->klassci_token;

            Log::info('Toggle visio séance', [
                'klassci_seance_id' => $seanceId,
                'enabled' => $enabled,
                'visio_type' => $visioType,
                'user_id' => $user->id
            ]);

            // WORKAROUND: On ne peut pas récupérer la séance via GET /seances/{id} (endpoint inexistant dans KLASSCI)
            // On crée/met à jour directement l'entrée locale
            // Les IDs matiere/classe/enseignant seront null, mais ce n'est pas critique car
            // les données réelles viennent toujours de KLASSCI via /matieres/{id}

            Log::info('Création/MAJ entrée visio locale sans vérification KLASSCI', [
                'klassci_seance_id' => $seanceId,
                'note' => 'Workaround: endpoint GET /seances/{id} inexistant dans KLASSCI'
            ]);

            // Créer/Mettre à jour l'entrée visio locale
            $visio = \App\Models\Seance::updateOrCreate(
                ['klassci_seance_id' => $seanceId],
                [
                    // On ne peut pas récupérer ces IDs car pas d'endpoint dédié
                    // Ce n'est pas grave, les données viennent de /matieres/{id}
                    'klassci_matiere_id' => null,
                    'klassci_classe_id' => null,
                    'klassci_enseignant_id' => null,

                    // Données visio (ce qui nous intéresse vraiment)
                    'visio_enabled' => $enabled,
                    'visio_type' => $enabled ? $visioType : null,
                    'visio_status' => $enabled ? 'programmee' : null,
                    'visio_room_id' => $enabled ? 'lms_seance_' . $seanceId . '_' . time() : null,
                    'visio_active' => false,
                    'updated_by' => $user->id,
                ]
            );

            if ($visio->wasRecentlyCreated) {
                $visio->created_by = $user->id;
                $visio->save();
            }

            // NOUVEAU: Envoyer notifications quand visio est activée (pattern Forum/Lessons)
            $notificationsSent = 0;
            if ($enabled) {
                try {
                    // Récupérer les infos de la séance depuis Klassci pour avoir classe_id et matiere
                    $seanceData = $this->getSeanceDataFromKlassci($seanceId, $klassciToken);

                    if ($seanceData) {
                        // Mettre à jour les IDs manquants dans la séance locale
                        $visio->update([
                            'klassci_matiere_id' => $seanceData['matiere_id'] ?? null,
                            'klassci_classe_id' => $seanceData['classe_id'] ?? null,
                            'klassci_enseignant_id' => $seanceData['enseignant_id'] ?? null,
                            'matiere_nom' => $seanceData['matiere_nom'] ?? null,
                            'enseignant_nom' => $seanceData['enseignant_nom'] ?? null,
                        ]);

                        // Synchroniser la classe pour avoir les étudiants
                        if (isset($seanceData['classe_id'])) {
                            $this->classeSyncService->syncClasseById(
                                $seanceData['classe_id'],
                                $klassciToken
                            );
                        }

                        // Envoyer les notifications immédiatement (comme Forum/Lessons)
                        $notificationsSent = $this->notificationService->notifyVisioScheduled($seanceId, [
                            'klassci_classe_id' => $seanceData['classe_id'] ?? null,
                            'klassci_enseignant_id' => $seanceData['enseignant_id'] ?? null,
                            'matiere_nom' => $seanceData['matiere_nom'] ?? 'Matière',
                            'enseignant_nom' => $seanceData['enseignant_nom'] ?? 'Enseignant',
                        ]);

                        Log::info('Notifications visio envoyées via toggleVisio', [
                            'seance_id' => $seanceId,
                            'notifications_sent' => $notificationsSent
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Erreur envoi notifications via toggleVisio', [
                        'seance_id' => $seanceId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => $enabled ? 'Visioconférence activée avec succès' : 'Visioconférence désactivée',
                'data' => [
                    'seance_id' => $seanceId,
                    'visio_enabled' => $visio->visio_enabled,
                    'visio_type' => $visio->visio_type,
                    'visio_room_id' => $visio->visio_room_id,
                    'visio_active' => $visio->visio_active,
                    'notifications_sent' => $notificationsSent,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur toggle visio séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'activation/désactivation de la visio',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{seanceId}/activate-visio
     * Activer la visioconférence pour une séance
     *
     * Workflow: Enseignant active → status = 'programmee'
     */
    public function hideSeance(int $seanceId, \App\Http\Requests\HideSeanceRequest $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            // Vérifier que la séance existe
            $seance = \App\Models\Seance::where('id', $seanceId)
                ->orWhere('klassci_seance_id', $seanceId)
                ->first();

            if (!$seance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée'
                ], 404);
            }

            // Masquer la séance
            \App\Models\SeanceUserHidden::hide($seance->id, $user->id);

            Log::info('Séance masquée par étudiant', [
                'seance_id' => $seance->id,
                'user_id' => $user->id,
                'matiere' => $seance->matiere_nom
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Séance masquée avec succès',
                'data' => [
                    'seance_id' => $seance->id,
                    'hidden_at' => now()->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur masquage séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du masquage de la séance',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{id}/unhide
     * Réafficher une séance précédemment masquée
     */
    public function unhideSeance(int $seanceId, \App\Http\Requests\UnhideSeanceRequest $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            // Vérifier que la séance existe
            $seance = \App\Models\Seance::where('id', $seanceId)
                ->orWhere('klassci_seance_id', $seanceId)
                ->first();

            if (!$seance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée'
                ], 404);
            }

            // Réafficher la séance
            $unHidden = \App\Models\SeanceUserHidden::unhide($seance->id, $user->id);

            if (!$unHidden) {
                return response()->json([
                    'success' => false,
                    'message' => 'La séance n\'était pas masquée'
                ], 404);
            }

            Log::info('Séance réaffichée par étudiant', [
                'seance_id' => $seance->id,
                'user_id' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Séance réaffichée avec succès',
                'data' => [
                    'seance_id' => $seance->id
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur réaffichage séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du réaffichage de la séance',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/seances/{seanceId}/attendances
     * Présences détaillées pour une séance donnée
     */
    public function deleteSeance(\App\Http\Requests\DeleteSeanceRequest $request, int $seanceId): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            // Chercher par ID local d'abord, puis par klassci_seance_id
            $seance = \App\Models\Seance::find($seanceId);
            if (!$seance) {
                $seance = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();
            }

            // Empêcher la suppression si la visio est en cours
            if ($seance->visio_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer une séance dont la visioconférence est en cours.',
                ], 422);
            }

            Log::info('Suppression séance', [
                'seance_id' => $seance->id,
                'klassci_seance_id' => $seance->klassci_seance_id,
                'deleted_by' => $user->id,
                'user_role' => $user->role,
            ]);

            // Soft delete
            $seance->delete();

            return response()->json([
                'success' => true,
                'message' => 'Séance supprimée avec succès',
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur suppression séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la séance',
                'error' => 'Une erreur est survenue.',
            ], 500);
        }
    }
}
