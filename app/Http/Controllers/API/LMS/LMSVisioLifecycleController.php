<?php

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\ActivateVisioRequest;
use App\Http\Requests\DeactivateVisioRequest;
use App\Http\Requests\EndVisioRequest;
use App\Http\Requests\HeartbeatVisioRequest;
use App\Http\Requests\JoinVisioRequest;
use App\Http\Requests\LeaveVisioRequest;
use App\Http\Requests\StartVisioRequest;
use App\Services\AttendanceStatusService;
use App\Services\KlassciProxyService;
use App\Services\SeanceQueryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * LMSVisioLifecycleController — extrait verbatim de LMSVisioController.
 * Refactor du god-controller (984 lignes -> 2 fichiers SRP).
 * Aucun changement comportemental.
 */
class LMSVisioLifecycleController extends AuthenticatedController
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly SeanceQueryService $seanceQuery,
        private readonly AttendanceStatusService $attendanceStatus,
    ) {}

    public function activateVisio(int $seanceId, ActivateVisioRequest $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $klassciToken = $user->klassci_token;

            // Vérifier que la séance existe dans KLASSCI
            // Pour enseignants: utiliser teacher-dashboard
            // Pour coordinateurs: utiliser /matieres
            $seanceFound = null;
            $matiereInfo = null;

            if ($user->isTeacher()) {
                $dashboard = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    'me/teacher-dashboard',
                    'GET'
                );
                $matieres = collect($dashboard['data']['matieres'] ?? []);
            } else {
                $matieresResponse = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    'matieres',
                    'GET'
                );
                $matieres = collect($matieresResponse['data'] ?? []);
            }

            foreach ($matieres as $matiere) {
                $matiereDetails = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "matieres/{$matiere['id']}",
                    'GET'
                );

                $seances = collect($matiereDetails['data']['seances_programmees'] ?? []);
                $seanceFound = $seances->firstWhere('id', $seanceId);

                if ($seanceFound) {
                    $matiereInfo = $matiere;
                    break;
                }
            }

            if (!$seanceFound) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée'
                ], 404);
            }

            // Créer ou mettre à jour l'entrée visio
            $visio = \App\Models\Seance::updateOrCreate(
                ['klassci_seance_id' => $seanceId],
                [
                    'klassci_matiere_id' => $matiereInfo['id'] ?? null,
                    'klassci_classe_id' => $seanceFound['classe']['id'] ?? null,
                    'klassci_enseignant_id' => $user->klassci_id,
                    'enseignant_nom' => $user->name,
                    'matiere_nom' => $matiereInfo['nom'] ?? $matiereInfo['libelle'] ?? null,
                    'visio_enabled' => true,
                    'visio_type' => 'jitsi',
                    'visio_status' => 'programmee',
                    'visio_room_id' => 'lms_seance_' . $seanceId . '_' . time(),
                    'visio_active' => false,
                    'is_active' => true,  // S'assurer que la séance est active pour être visible aux étudiants
                    'updated_by' => $user->id,
                ]
            );

            Log::info('Visio activée', [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
                'room_id' => $visio->visio_room_id
            ]);

            // Synchroniser la classe et ses étudiants depuis Klassci
            // pour que les notifications puissent être envoyées
            $classe = null;
            try {
                if ($visio->klassci_classe_id) {
                    Log::info('Synchronisation classe pour notifications', [
                        'klassci_classe_id' => $visio->klassci_classe_id
                    ]);

                    $classe = $this->classeSyncService->syncClasseById(
                        $visio->klassci_classe_id,
                        $klassciToken
                    );

                    if ($classe) {
                        // Compter les étudiants synchronisés
                        $etudiantsCount = $classe->etudiantsActifs()->count();
                        Log::info('Classe synchronisée avec étudiants', [
                            'classe_id' => $classe->id,
                            'klassci_id' => $classe->klassci_id,
                            'libelle' => $classe->libelle,
                            'etudiants_actifs' => $etudiantsCount
                        ]);
                    } else {
                        Log::warning('Synchronisation classe échouée - classe null', [
                            'klassci_classe_id' => $visio->klassci_classe_id
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Erreur synchronisation classe', [
                    'seance_id' => $seanceId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }

            // Envoyer les notifications aux étudiants
            $notificationsSent = 0;
            try {
                $notificationsSent = $this->notificationService->notifyVisioScheduled($seanceId, [
                    'klassci_classe_id' => $visio->klassci_classe_id,
                    'klassci_enseignant_id' => $visio->klassci_enseignant_id,
                    'matiere_nom' => $visio->matiere_nom,
                    'enseignant_nom' => $visio->enseignant_nom,
                ]);

                Log::info('Notifications visio programmée envoyées', [
                    'seance_id' => $seanceId,
                    'notifications_sent' => $notificationsSent,
                    'classe_local_id' => $classe ? $classe->id : null
                ]);
            } catch (\Exception $e) {
                Log::error('Erreur envoi notifications visio programmée', [
                    'seance_id' => $seanceId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Visioconférence activée',
                'data' => [
                    'visio_enabled' => true,
                    'visio_status' => 'programmee',
                    'visio_room_id' => $visio->visio_room_id,
                    'notifications_sent' => $notificationsSent,
                    'classe_synced' => $classe ? true : false
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur activation visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'activation',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{seanceId}/deactivate-visio
     * Désactiver la visioconférence pour une séance
     *
     * Workflow: Enseignant désactive → visio_enabled = false
     */
    public function deactivateVisio(int $seanceId, DeactivateVisioRequest $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            $visio = \App\Models\Seance::find($seanceId);
            if (!$visio) {
                $visio = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();
            }

            if (!$visio || !$visio->visio_enabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Visio non activée pour cette séance'
                ], 404);
            }

            // Désactiver la visio
            $visio->update([
                'visio_enabled' => false,
                'visio_type' => null,
                'visio_status' => null,
                'visio_room_id' => null,
                'visio_active' => false,
                'visio_started_at' => null,
                'updated_by' => $user->id,
            ]);

            Log::info('Visio désactivée', [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Visioconférence désactivée',
                'data' => [
                    'visio_enabled' => false
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur désactivation visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la désactivation',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{seanceId}/start-visio
     * Démarrer la visioconférence
     *
     * Workflow: Enseignant démarre → status = 'active'
     */
    public function startVisio(int $seanceId, StartVisioRequest $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            $visio = \App\Models\Seance::find($seanceId);
            if (!$visio) {
                $visio = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();
            }

            if (!$visio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Visio non activée pour cette séance'
                ], 404);
            }

            if (!$visio->visio_enabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'La visio doit être activée avant de démarrer'
                ], 400);
            }

            // Démarrer la visio
            $visio->update([
                'visio_status' => 'active',
                'visio_active' => true,
                'visio_started_at' => now(),
                'updated_by' => $user->id,
            ]);

            Log::info('Visio démarrée', [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
                'started_at' => $visio->visio_started_at
            ]);

            // Synchroniser la classe si pas déjà fait (sécurité)
            // Normalement déjà fait lors de activateVisio, mais on s'assure
            try {
                if ($visio->klassci_classe_id) {
                    $klassciToken = $user->klassci_token;
                    if ($klassciToken) {
                        $classe = $this->classeSyncService->syncClasseById(
                            $visio->klassci_classe_id,
                            $klassciToken
                        );
                    }
                }
            } catch (\Exception $e) {
                Log::error('Erreur synchronisation classe au démarrage', [
                    'seance_id' => $seanceId,
                    'error' => $e->getMessage()
                ]);
            }

            // Envoyer les notifications aux étudiants et à l'enseignant
            try {
                $notificationsSent = $this->notificationService->notifyVisioStarting($seanceId, [
                    'klassci_classe_id' => $visio->klassci_classe_id,
                    'klassci_enseignant_id' => $visio->klassci_enseignant_id,
                    'matiere_nom' => $visio->matiere_nom,
                    'enseignant_nom' => $visio->enseignant_nom,
                ]);

                Log::info('Notifications visio démarrée envoyées', [
                    'seance_id' => $seanceId,
                    'notifications_sent' => $notificationsSent
                ]);
            } catch (\Exception $e) {
                Log::error('Erreur envoi notifications visio démarrée', [
                    'seance_id' => $seanceId,
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Visioconférence démarrée',
                'data' => [
                    'visio_status' => 'active',
                    'visio_started_at' => $visio->visio_started_at,
                    'visio_room_id' => $visio->visio_room_id
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur démarrage visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du démarrage',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{seanceId}/end-visio
     * Terminer la visioconférence manuellement
     *
     * Workflow: Enseignant termine → status = 'terminee'
     */
    public function endVisio(int $seanceId, EndVisioRequest $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            $visio = \App\Models\Seance::find($seanceId);
            if (!$visio) {
                $visio = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();
            }

            if (!$visio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Visio non trouvée'
                ], 404);
            }

            if ($visio->visio_status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'La visio n\'est pas active'
                ], 400);
            }

            // Terminer la visio
            $visio->update([
                'visio_status' => 'terminee',
                'visio_active' => false,
                'visio_ended_at' => now(),
                'updated_by' => $user->id,
            ]);

            Log::info('Visio terminée', [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
                'ended_at' => $visio->visio_ended_at,
                'participants_count' => $visio->current_participants_count
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Visioconférence terminée',
                'data' => [
                    'visio_status' => 'terminee',
                    'visio_ended_at' => $visio->visio_ended_at,
                    'participants_count' => $visio->current_participants_count
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur fin visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la terminaison',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }
}
