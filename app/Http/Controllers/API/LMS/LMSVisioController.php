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
 * LMS Visio — actions visio (activate/deactivate/start/end/join/leave/heartbeat) +
 * liste des participants visio.
 *
 * Extracted from `LMSDataController` (PR I of split spec). 8 methods + 1 helper
 * removed via service migration.
 *
 * ## Architecture wins consolidated in this PR
 *
 *  1. `getVisioParticipants()` previously called `$this->seanceDetails(...)` twice
 *     to read séance metadata, decoded the JSON response, and read fields back.
 *     Anti-pattern eliminated: now calls `SeanceQueryService::getSeanceDetailsArray()`
 *     directly (PR E).
 *
 *  2. The same method also called the private helper `determineAttendanceStatus(...)`
 *     to compute the late/early-leave labels. Anti-pattern eliminated: now calls
 *     `AttendanceStatusService::determine(...)` (PR G).
 *
 *  3. Route conflict (REQ-4 of the spec): the legacy route
 *     `GET /lms/seances/{id}/participants` was registered twice — once for
 *     `seanceParticipants` (LMSSeancesController, lists enrolled students) and
 *     once for `getVisioParticipants` (this controller, lists currently-
 *     connected visio participants). Laravel matched the first, making the
 *     visio route unreachable. Resolved by renaming the visio route to
 *     `GET /lms/seances/{id}/visio-participants`.
 *
 * ## Frontend coordination
 *
 * The route rename (REQ-4) is a breaking change for the `lms-frontend` if it
 * relied on `getVisioParticipants`. Since the legacy route was UNREACHABLE
 * (matched the seanceParticipants route first), no frontend client could
 * have been depending on it — the rename is safe.
 */
final class LMSVisioController extends AuthenticatedController
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

            if (in_array($user->role, ['enseignant', 'teacher'])) {
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

    /**
     * POST /api/lms/seances/{seanceId}/join
     * Logger qu'un étudiant rejoint la visio
     */
    public function joinVisio(int $seanceId, JoinVisioRequest $request): JsonResponse
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

            // 🔑 RÈGLE PARTICIPANT FANTÔME
            // Les coordinateurs sont marqués comme "observateurs" (is_observer=true)
            // Ils ne sont PAS affichés dans la liste des participants visible
            // Mais leur présence est tracée pour l'audit
            $isObserver = ($user->role === 'coordinateur');

            // Enregistrer la participation pour tous les rôles
            // Étudiants, enseignants et coordinateurs peuvent rejoindre la visio
            $attendance = \App\Models\ESBTPAttendance::updateOrCreate(
                [
                    'seance_id' => $visio->id,
                    'user_id' => $user->id
                ],
                [
                    'klassci_etudiant_id' => $user->klassci_id,
                    'nom' => $user->name,
                    'prenom' => '',
                    'email' => $user->email,
                    'joined_at' => now(),
                    'last_seen_at' => now(),
                    'status' => 'connected',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'is_validated' => true,
                    'is_observer' => $isObserver
                ]
            );

            $roleLabel = match($user->role) {
                'coordinateur' => 'Coordinateur (observateur)',
                'enseignant' => 'Enseignant',
                default => 'Étudiant'
            };

            Log::info("$roleLabel rejoint visio - participation enregistrée", [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
                'role' => $user->role,
                'is_observer' => $isObserver,
                'attendance_id' => $attendance->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Accès à la visio autorisé',
                'data' => [
                    'visio_room_id' => $visio->visio_room_id,
                    'participants_count' => $visio->current_participants_count
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur join visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la connexion',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/seances/{seanceId}/participants
     * Récupérer la liste des participants réels à une visio
     */
    public function getVisioParticipants(int $seanceId, Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            $visio = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();

            if (!$visio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée'
                ], 404);
            }

            // 0. Déconnecter automatiquement les participants inactifs (timeout 3 minutes)
            // 🔑 SYSTÈME DE TIMEOUT : Détecte les participants qui n'envoient plus de heartbeat
            // et les marque automatiquement comme déconnectés
            $disconnectedCount = \App\Models\ESBTPAttendance::disconnectInactiveParticipants(3);
            if ($disconnectedCount > 0) {
                \Log::info('Participants déconnectés par timeout', [
                    'seance_id' => $seanceId,
                    'count' => $disconnectedCount
                ]);
            }

            // 1. Récupérer les participants qui ont RÉELLEMENT rejoint (table esbtp_attendance)
            // 🔑 RÈGLE PARTICIPANT FANTÔME : Exclure les observateurs (coordinateurs)
            // Les observateurs ne sont PAS affichés dans la liste visible des participants
            $actualParticipants = \App\Models\ESBTPAttendance::where('seance_id', $visio->id)
                ->where('is_observer', false)  // 🔑 EXCLUSION DES OBSERVATEURS
                ->with('user:id,name,email,klassci_id')
                ->orderBy('joined_at', 'desc')
                ->get();

            // 2. Calculer la durée théorique de la séance (depuis programmation)
            $seanceDurationMinutes = 120; // Valeur par défaut
            $heureDebut = null;
            $heureFin = null;

            try {
                $seanceArray = $this->seanceQuery->getSeanceDetailsArray($seanceId, $user);

                if ($seanceArray !== null && isset($seanceArray['seance']['programmation'])) {
                    $prog = $seanceArray['seance']['programmation'];
                    $heureDebut = $prog['heure_debut'] ?? null;
                    $heureFin = $prog['heure_fin'] ?? null;

                    if ($heureDebut && $heureFin) {
                        $debut = new \DateTime($heureDebut);
                        $fin = new \DateTime($heureFin);
                        $seanceDurationMinutes = ($fin->getTimestamp() - $debut->getTimestamp()) / 60;
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Impossible de calculer la durée de la séance', [
                    'seance_id' => $seanceId,
                    'error' => $e->getMessage()
                ]);
            }

            // 3. Récupérer TOUS les étudiants de la classe (pour comparaison)
            $allClassStudents = [];

            try {
                $seanceArray = $this->seanceQuery->getSeanceDetailsArray($seanceId, $user);

                if ($seanceArray !== null && isset($seanceArray['participants']['students'])) {
                    $allClassStudents = $seanceArray['participants']['students'];
                }
            } catch (\Exception $e) {
                Log::warning('Impossible de récupérer la liste complète des étudiants via SeanceQueryService', [
                    'seance_id' => $seanceId,
                    'error' => $e->getMessage()
                ]);
            }

            // Fallback : Si pas d'étudiants via KLASSCI, chercher dans la BDD locale
            if (empty($allClassStudents) && $visio->klassci_classe_id) {
                try {
                    Log::info('Fallback: Recherche étudiants via BDD locale', [
                        'seance_id' => $seanceId,
                        'classe_id' => $visio->klassci_classe_id
                    ]);

                    $students = \App\Models\UserClass::where('klassci_classe_id', $visio->klassci_classe_id)
                        ->join('users', 'user_classes.user_id', '=', 'users.id')
                        ->where('users.role', 'etudiant')
                        ->select('users.id', 'users.name', 'users.email', 'users.klassci_id')
                        ->get();

                    $allClassStudents = $students->map(function($student) {
                        // Parser le nom complet (format: "NOM Prenom")
                        $nameParts = explode(' ', $student->name, 2);
                        return [
                            'id' => $student->id,
                            'nom' => $nameParts[0] ?? $student->name,
                            'prenom' => $nameParts[1] ?? '',
                            'email' => $student->email,
                            'klassci_id' => $student->klassci_id
                        ];
                    })->toArray();

                    Log::info('Fallback BDD: Étudiants trouvés', [
                        'count' => count($allClassStudents)
                    ]);
                } catch (\Exception $e) {
                    Log::error('Erreur fallback BDD pour récupération étudiants', [
                        'seance_id' => $seanceId,
                        'classe_id' => $visio->klassci_classe_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // 4. Créer un index des participants réels par user_id
            $actualParticipantsById = [];
            foreach ($actualParticipants as $attendance) {
                $actualParticipantsById[$attendance->user_id] = $attendance;
            }

            // 5. Construire la liste unifiée avec statut et pourcentage
            $unifiedList = [];

            foreach ($allClassStudents as $student) {
                $studentId = $student['id'];
                $isPresent = isset($actualParticipantsById[$studentId]);

                $studentData = [
                    'user_id' => $studentId,
                    'nom' => $student['nom'] ?? '',
                    'prenom' => $student['prenom'] ?? '',
                    'email' => $student['email'] ?? '',
                ];

                if ($isPresent) {
                    // Étudiant PRÉSENT - ajouter les détails de participation
                    $attendance = $actualParticipantsById[$studentId];

                    // Calculer la durée dynamiquement si l'étudiant est toujours connecté
                    if ($attendance->status === 'connected' && $attendance->joined_at) {
                        // Si toujours connecté, calculer la durée depuis joined_at jusqu'à maintenant
                        $durationMinutes = $attendance->joined_at->diffInMinutes(now());
                    } else {
                        // Si déconnecté, utiliser la durée enregistrée
                        $durationMinutes = $attendance->duration_minutes ?? 0;
                    }

                    $percentage = $seanceDurationMinutes > 0
                        ? round(($durationMinutes / $seanceDurationMinutes) * 100)
                        : 0;

                    // Déterminer le statut
                    $status = $this->attendanceStatus->determine(
                        $percentage,
                        $attendance->joined_at?->toIso8601String(),
                        $attendance->left_at?->toIso8601String(),
                        $heureDebut,
                        $heureFin,
                        $visio->visio_status,
                    );

                    // Formatter la durée dynamiquement
                    $hours = floor($durationMinutes / 60);
                    $minutes = $durationMinutes % 60;
                    $durationFormatted = $hours > 0 ? "{$hours}h {$minutes}min" : "{$minutes}min";

                    // Déterminer left_at dynamiquement si toujours connecté
                    $leftAt = $attendance->left_at;
                    $leftAtFull = $attendance->left_at?->format('Y-m-d H:i:s');
                    $leftAtDisplay = $attendance->left_at?->format('H:i');

                    if ($attendance->status === 'connected') {
                        $leftAtDisplay = 'En cours';
                        $leftAtFull = null; // NULL = toujours connecté
                    }

                    $studentData = array_merge($studentData, [
                        'status' => $status['label'],
                        'status_icon' => $status['icon'],
                        'percentage' => $percentage,
                        'duration_minutes' => $durationMinutes,
                        'duration_formatted' => $durationFormatted,
                        'joined_at' => $attendance->joined_at?->format('H:i'),
                        'left_at' => $leftAtDisplay,
                        'joined_at_full' => $attendance->joined_at?->format('Y-m-d H:i:s'),
                        'left_at_full' => $leftAtFull,
                        'is_late' => $status['is_late'],
                        'left_early' => $status['left_early'],
                        'is_present' => true,
                        'is_connected' => $attendance->status === 'connected' // Nouveau champ
                    ]);
                } else {
                    // Étudiant ABSENT
                    $studentData = array_merge($studentData, [
                        'status' => 'Absent',
                        'status_icon' => '❌',
                        'percentage' => 0,
                        'duration_minutes' => 0,
                        'duration_formatted' => '-',
                        'joined_at' => null,
                        'left_at' => null,
                        'joined_at_full' => null,
                        'left_at_full' => null,
                        'is_late' => false,
                        'left_early' => false,
                        'is_present' => false
                    ]);
                }

                $unifiedList[] = $studentData;
            }

            // 6. Trier la liste : Présents d'abord, puis par pourcentage décroissant, puis absents
            usort($unifiedList, function($a, $b) {
                // Présents avant absents
                if ($a['is_present'] !== $b['is_present']) {
                    return $b['is_present'] <=> $a['is_present'];
                }
                // Si tous deux présents, trier par pourcentage décroissant
                if ($a['is_present'] && $b['is_present']) {
                    return $b['percentage'] <=> $a['percentage'];
                }
                // Si tous deux absents, ordre alphabétique
                return strcmp($a['nom'], $b['nom']);
            });

            // 7. Calculer les statistiques globales
            // Compter seulement les participants ENCORE CONNECTÉS (pas ceux qui ont quitté)
            $presentCount = count(array_filter($unifiedList, fn($s) => ($s['is_connected'] ?? false)));
            $absentCount = count($unifiedList) - $presentCount;
            $lateCount = count(array_filter($unifiedList, fn($s) => $s['is_late'] ?? false));
            $leftEarlyCount = count(array_filter($unifiedList, fn($s) => $s['left_early'] ?? false));
            $completePresenceCount = count(array_filter($unifiedList, fn($s) => ($s['percentage'] ?? 0) === 100));

            // Calculer le pourcentage moyen de présence
            $totalPercentage = array_reduce($unifiedList, fn($sum, $s) => $sum + ($s['percentage'] ?? 0), 0);
            $averagePercentage = count($unifiedList) > 0 ? round($totalPercentage / count($unifiedList)) : 0;

            // Calculer la durée moyenne
            $totalDuration = array_reduce($unifiedList, fn($sum, $s) => $sum + ($s['duration_minutes'] ?? 0), 0);
            $averageDuration = $presentCount > 0 ? round($totalDuration / $presentCount) : 0;

            $stats = [
                'total_students' => count($unifiedList),
                'present_count' => $presentCount,
                'absent_count' => $absentCount,
                'presence_rate' => count($unifiedList) > 0 ? round(($presentCount / count($unifiedList)) * 100) : 0,
                'complete_presence_count' => $completePresenceCount,
                'late_count' => $lateCount,
                'left_early_count' => $leftEarlyCount,
                'average_percentage' => $averagePercentage,
                'average_duration_minutes' => $averageDuration,
                'seance_duration_minutes' => $seanceDurationMinutes,
                'visio_status' => $visio->visio_status  // Ajouter pour conditionner affichage %
            ];

            // Récupérer l'info enseignant depuis la table seances
            $teacherInfo = [
                'nom' => $visio->enseignant_nom ?? null,
                'prenom' => $visio->enseignant_prenom ?? null,
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'students' => $unifiedList,
                    'statistics' => $stats,
                    'teacher' => $teacherInfo,
                    'seance' => [
                        'id' => $seanceId,
                        'matiere' => $visio->matiere_nom,
                        'classe_id' => $visio->klassci_classe_id,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération participants', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des participants',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * Détermine le statut de présence d'un étudiant
     */
    public function leaveVisio(int $seanceId, LeaveVisioRequest $request): JsonResponse
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

            // Trouver la participation active de l'utilisateur
            $attendance = \App\Models\ESBTPAttendance::where('seance_id', $visio->id)
                ->where('user_id', $user->id)
                ->where('status', 'connected')
                ->orderBy('joined_at', 'desc')
                ->first();

            if (!$attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune participation active trouvée'
                ], 404);
            }

            // Marquer comme déconnecté
            $attendance->markAsDisconnected();

            Log::info('Participant quitté visio', [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
                'duration_minutes' => $attendance->duration_minutes
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Déconnexion enregistrée',
                'data' => [
                    'left_at' => $attendance->left_at->format('Y-m-d H:i:s'),
                    'duration_minutes' => $attendance->duration_minutes,
                    'duration_formatted' => $attendance->formatted_duration
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur leave visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'enregistrement de la sortie',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/seances/{seanceId}/heartbeat
     * Mettre à jour le heartbeat d'un participant (ping d'activité)
     */
    public function heartbeatVisio(int $seanceId, HeartbeatVisioRequest $request): JsonResponse
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

            // Trouver la participation active de l'utilisateur
            $attendance = \App\Models\ESBTPAttendance::where('seance_id', $visio->id)
                ->where('user_id', $user->id)
                ->where('status', 'connected')
                ->orderBy('joined_at', 'desc')
                ->first();

            if (!$attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune participation active trouvée'
                ], 404);
            }

            // Mettre à jour le heartbeat
            $attendance->updateHeartbeat();

            return response()->json([
                'success' => true,
                'data' => [
                    'last_seen_at' => $attendance->last_seen_at->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur heartbeat visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du heartbeat',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * POST /api/lms/seances/{id}/hide
     * Masquer une séance pour l'utilisateur connecté
     *
     * Permet à un étudiant de masquer une séance de sa vue personnelle
     * sans affecter les autres utilisateurs
     */
}
