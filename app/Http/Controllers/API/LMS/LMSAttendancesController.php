<?php

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\SyncAttendancesRequest;
use App\Services\AttendanceStatusService;
use App\Services\KlassciProxyService;
use App\Services\SeanceDetailQueryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * LMS Attendances — synchronisation et historique des présences.
 *
 * Extracted from `LMSDataController` (PR H of split spec). 3 methods.
 *
 * Responsibilities:
 *   - POST /api/lms/attendances/from-video-session  → syncAttendancesFromVideoSession()
 *   - GET  /api/lms/attendance/history              → getAttendanceHistory()
 *                                                     (uses SeanceDetailQueryService)
 *   - GET  /api/lms/seances/{id}/attendances        → getSeanceAttendances()
 *
 * Injects 3 services:
 *   - KlassciProxyService — for direct KLASSCI calls
 *   - SeanceDetailQueryService (split-1, ex-SeanceQueryService) — replaces legacy
 *     `$this->seanceDetails(...)` anti-pattern. Used by `getAttendanceHistory`
 *     to enrich attendance rows with séance metadata.
 *   - AttendanceStatusService (PR G) — currently not used directly in these
 *     3 methods, but pre-wired for future use (e.g. when `getSeanceAttendances`
 *     gets a status-decoration feature). Removing it keeps the constructor
 *     consistent with the legacy intent.
 */
final class LMSAttendancesController extends AuthenticatedController
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly SeanceDetailQueryService $seanceQuery,
        private readonly AttendanceStatusService $attendanceStatus,
    ) {}

    public function syncAttendancesFromVideoSession(\App\Http\Requests\SyncAttendancesRequest $request): JsonResponse
    {
        try {
            $seanceCoursId = $request->validated('seance_cours_id');
            $date = $request->validated('date');
            $participants = $request->validated('participants');

            Log::info('Synchronisation attendances vidéo', [
                'seance_cours_id' => $seanceCoursId,
                'date' => $date,
                'nb_participants' => count($participants)
            ]);

            $created = 0;
            $updated = 0;
            $errors = [];

            foreach ($participants as $participant) {
                try {
                    $etudiantId = $participant['etudiant_id'];
                    $statut = $participant['statut'];
                    $joinedAt = $participant['joined_at'] ?? null;
                    $leftAt = $participant['left_at'] ?? null;
                    $durationMinutes = $participant['duration_minutes'] ?? null;

                    $existingAttendance = \App\Models\ESBTPAttendance::where('seance_cours_id', $seanceCoursId)
                        ->where('etudiant_id', $etudiantId)
                        ->where('date', $date)
                        ->first();

                    $commentaireHistorique = $existingAttendance ? ($existingAttendance->commentaire ?? '') : '';
                    $newCommentaire = $commentaireHistorique .
                        "\n[" . now()->format('Y-m-d H:i:s') . "] Sync vidéo: " .
                        ($joinedAt ? "rejoint {$joinedAt}" : '') .
                        ($leftAt ? ", quitté {$leftAt}" : '') .
                        ($durationMinutes ? ", durée {$durationMinutes}min" : '');

                    $attendance = \App\Models\ESBTPAttendance::updateOrCreate(
                        [
                            'seance_cours_id' => $seanceCoursId,
                            'etudiant_id' => $etudiantId,
                            'date' => $date,
                        ],
                        [
                            'statut' => $statut,
                            'call_type' => 'merged',
                            'video_joined_at' => $joinedAt,
                            'video_left_at' => $leftAt,
                            'video_duration_minutes' => $durationMinutes,
                            'commentaire' => trim($newCommentaire),
                            'updated_by' => $this->authenticatedUser($request)->id
                        ]
                    );

                    if ($attendance->wasRecentlyCreated) {
                        $created++;
                    } else {
                        $updated++;
                    }

                } catch (\Exception $e) {
                    Log::error('Erreur sync attendance pour étudiant', [
                        'etudiant_id' => $participant['etudiant_id'] ?? null,
                        'error' => $e->getMessage()
                    ]);

                    $errors[] = [
                        'etudiant_id' => $participant['etudiant_id'] ?? null,
                        'error' => 'Une erreur est survenue.'
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Attendances synchronisées avec succès',
                'data' => [
                    'created' => $created,
                    'updated' => $updated,
                    'errors' => $errors
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur synchronisation attendances vidéo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation des attendances',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/notifications/preferences/{userId}
     *
     * @deprecated Migrated to {@see \App\Http\Controllers\API\LMS\LMSNotificationsPreferencesController::getNotificationPreferences}.
     *             This copy is unreachable (routes/api.php now points to the new controller) and will be
     *             removed in Phase C cleanup (PR J of the LMS split spec).
     *             DO NOT modify this version — fix the new controller instead.
     */
    public function getAttendanceHistory(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            // Paramètres de filtrage
            $seanceId = $request->input('seance_id');
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
            $matiereId = $request->input('matiere_id');
            $classeId = $request->input('classe_id');

            // Construire la requête de base
            $query = \App\Models\ESBTPAttendance::with(['user:id,name,email', 'seance'])
                ->orderBy('joined_at', 'desc');

            // Filtre selon le rôle
            if ($user->isTeacher()) {
                // L'enseignant ne voit que les présences de ses propres séances
                $query->whereHas('seance', function($q) use ($user) {
                    $q->where('enseignant_id', $user->id);
                });
            } else if ($user->isStudent()) {
                // L'étudiant ne voit que ses propres présences
                $query->where('user_id', $user->id);
            }
            // Les coordinateurs/superAdmin voient tout

            // Filtres optionnels
            if ($seanceId) {
                $seanceLocal = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();
                if ($seanceLocal) {
                    $query->where('seance_id', $seanceLocal->id);
                }
            }

            if ($dateFrom) {
                $query->where('joined_at', '>=', $dateFrom);
            }

            if ($dateTo) {
                $query->where('joined_at', '<=', $dateTo . ' 23:59:59');
            }

            // Pagination
            $perPage = $request->input('per_page', 50);
            $attendances = $query->paginate($perPage);

            // Enrichir les données avec les infos KLASSCI si disponibles
            $enrichedData = $attendances->getCollection()->map(function($attendance) use ($request) {
                $data = [
                    'id' => $attendance->id,
                    'user' => [
                        'id' => $attendance->user?->id ?? 0,
                        'name' => $attendance->user?->name ?? 'Utilisateur supprimé',
                        'email' => $attendance->user?->email ?? '',
                    ],
                    'seance' => [
                        'id' => $attendance->seance?->id ?? 0,
                        'klassci_seance_id' => $attendance->seance?->klassci_seance_id ?? 'N/A',
                        'date' => $attendance->seance?->date_seance ?? null,
                        'matiere' => null,
                        'classe' => null
                    ],
                    'joined_at' => $attendance->joined_at?->format('Y-m-d H:i:s'),
                    'left_at' => $attendance->left_at?->format('Y-m-d H:i:s'),
                    'last_seen_at' => $attendance->last_seen_at?->format('Y-m-d H:i:s'),
                    'status' => $attendance->status,
                    'duration_minutes' => null,
                ];

                // Calculer la durée
                if ($attendance->joined_at && $attendance->left_at) {
                    $data['duration_minutes'] = $attendance->joined_at->diffInMinutes($attendance->left_at);
                }

                // Essayer de récupérer les infos KLASSCI de la séance
                if ($attendance->seance && $attendance->seance->klassci_seance_id) {
                    try {
                        // Use SeanceDetailQueryService (split-1, ex-SeanceQueryService PR E) instead of legacy
                        // `$this->seanceDetails($id, $request)` + json_decode anti-pattern.
                        // Returns the seance array directly — no encode/decode round-trip.
                        $seanceArray = $this->seanceQuery->getSeanceDetailsArray(
                            $attendance->seance->klassci_seance_id,
                            $user,
                        );

                        if ($seanceArray !== null && isset($seanceArray['seance'])) {
                            $data['seance']['matiere'] = $seanceArray['seance']['matiere'] ?? null;
                            $data['seance']['classe'] = $seanceArray['seance']['classe'] ?? null;
                            $data['seance']['programmation'] = $seanceArray['seance']['programmation'] ?? null;
                        }
                    } catch (\Exception $e) {
                        // Ignorer les erreurs de récupération KLASSCI (séance peut être archivée)
                        Log::debug('Impossible de récupérer détails KLASSCI pour historique', [
                            'seance_id' => $attendance->seance->klassci_seance_id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                return $data;
            });

            return response()->json([
                'success' => true,
                'data' => $enrichedData,
                'pagination' => [
                    'current_page' => $attendances->currentPage(),
                    'per_page' => $attendances->perPage(),
                    'total' => $attendances->total(),
                    'last_page' => $attendances->lastPage(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération historique présences', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'historique',
                'error' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * GET /api/lms/seances/history
     * Liste des séances ayant eu une session visio (avec statistiques de présence)
     */
    public function getSeanceAttendances(Request $request, int $seanceId): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);

            // Chercher par ID local d'abord, puis par klassci_seance_id
            $seance = \App\Models\Seance::find($seanceId);
            if (!$seance) {
                $seance = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();
            }

            if (!$seance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Séance non trouvée',
                ], 404);
            }

            // Contrôle d'accès par rôle
            if ($user->isTeacher()) {
                if ($seance->klassci_enseignant_id != $user->klassci_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Accès refusé. Cette séance ne vous appartient pas.',
                    ], 403);
                }
            }

            // Durée de la séance
            $seanceDurationMinutes = null;
            if ($seance->visio_started_at && $seance->visio_ended_at) {
                $diffMinutes = $seance->visio_started_at->diffInMinutes($seance->visio_ended_at);
                if ($diffMinutes <= 1440) {
                    $seanceDurationMinutes = $diffMinutes;
                }
            }

            $isFinished = $seance->visio_ended_at !== null;

            // Infos séance
            $seanceData = [
                'id' => $seance->id,
                'klassci_seance_id' => $seance->klassci_seance_id,
                'enseignant_nom' => $seance->enseignant_nom,
                'coordinateur_nom' => null,
                'visio_started_at' => $seance->visio_started_at?->toIso8601String(),
                'visio_ended_at' => $seance->visio_ended_at?->toIso8601String(),
                'duration_minutes' => $seanceDurationMinutes,
                'is_finished' => $isFinished,
                'matiere_nom' => $seance->matiere_nom ?? 'Matière inconnue',
                'date' => $seance->date_seance
                    ? $seance->date_seance->format('Y-m-d')
                    : ($seance->visio_started_at
                        ? $seance->visio_started_at->format('Y-m-d')
                        : null),
            ];

            // Récupérer toutes les attendances
            $allAttendances = \App\Models\ESBTPAttendance::where('seance_id', $seance->id)
                ->orderBy('joined_at', 'asc')
                ->get();

            // Statistiques (non-observateurs uniquement)
            $nonObserverAttendances = $allAttendances->filter(function ($att) {
                return !$att->is_observer;
            });

            $totalParticipants = $nonObserverAttendances->count();

            $durations = $nonObserverAttendances->pluck('duration_minutes')
                ->filter(fn($d) => $d !== null && $d > 0);

            $averageDuration = $durations->count() > 0
                ? round($durations->avg())
                : 0;

            $validPresences = $durations->filter(fn($d) => $d > 3)->count();

            $presenceRate = $totalParticipants > 0
                ? round(($validPresences / $totalParticipants) * 100)
                : 0;

            $statistics = [
                'total_participants' => $totalParticipants,
                'average_duration' => $averageDuration,
                'presence_rate' => $presenceRate,
            ];

            // Formater les attendances individuelles
            $formattedAttendances = $allAttendances->map(function ($att) use ($seanceDurationMinutes) {
                $durationMinutes = $att->duration_minutes ?? 0;

                // Pourcentage de participation par rapport à la durée de la séance
                $participationPercentage = 0;
                if ($seanceDurationMinutes && $seanceDurationMinutes > 0) {
                    $participationPercentage = min(100, round(($durationMinutes / $seanceDurationMinutes) * 100));
                }

                // Niveau de statut pour l'affichage frontend
                if ($durationMinutes <= 3) {
                    $statusLevel = 'danger';
                    $presenceStatus = 'Absent';
                } elseif ($participationPercentage >= 75) {
                    $statusLevel = 'success';
                    $presenceStatus = 'Présent';
                } elseif ($participationPercentage >= 25) {
                    $statusLevel = 'warning';
                    $presenceStatus = 'Partiel';
                } else {
                    $statusLevel = 'danger';
                    $presenceStatus = 'Insuffisant';
                }

                // Observateurs
                if ($att->is_observer) {
                    $statusLevel = 'info';
                    $presenceStatus = 'Observateur';
                }

                $fullName = trim(($att->prenom ?? '') . ' ' . ($att->nom ?? ''));
                if (empty($fullName)) {
                    $fullName = $att->email ?? 'Participant inconnu';
                }

                return [
                    'id' => $att->id,
                    'nom' => $fullName,
                    'email' => $att->email,
                    'joined_at' => $att->joined_at,
                    'left_at' => $att->left_at,
                    'duration_minutes' => $durationMinutes,
                    'status' => $att->status,
                    'status_level' => $statusLevel,
                    'presence_status' => $presenceStatus,
                    'participation_percentage' => $participationPercentage,
                    'is_observer' => (bool) $att->is_observer,
                ];
            });

            return response()->json([
                'success' => true,
                'seance' => $seanceData,
                'statistics' => $statistics,
                'attendances' => $formattedAttendances->values(),
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération attendances séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des présences',
                'error' => 'Une erreur est survenue.',
            ], 500);
        }
    }

    /**
     * DELETE /api/lms/seances/{seanceId}
     * Soft-delete d'une séance
     */
}
