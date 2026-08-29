<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\AttendanceHistoryRequest;
use App\Http\Requests\SyncAttendancesRequest;
use App\Services\Attendances\AttendanceHistoryQueryService;
use App\Services\Attendances\SeanceAttendancesQueryService;
use App\Services\Attendances\VideoSessionAttendancesSyncer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LMSAttendancesController — thin controller pour les présences LMS.
 *
 * Issu du split de l'ancien `LMSAttendancesController` (449 lignes, §5 violation)
 * en 3 services SRP dans `app/Services/Attendances/`. Aucun changement comportemental,
 * API publique préservée (mêmes routes, mêmes payloads).
 *
 * Endpoints :
 *   - POST /api/lms/attendances/from-video-session  → syncAttendancesFromVideoSession()
 *   - GET  /api/lms/attendance/history              → getAttendanceHistory()
 *   - GET  /api/lms/seances/{seanceId}/attendances  → getSeanceAttendances()
 *
 * @see PRODUCTION_STANDARDS.md §5 — Controllers ≤200 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict (constructor injection)
 */
final class LMSAttendancesController extends AuthenticatedController
{
    public function __construct(
        private readonly VideoSessionAttendancesSyncer $videoSessionSyncer,
        private readonly AttendanceHistoryQueryService $historyQuery,
        private readonly SeanceAttendancesQueryService $seanceAttendancesQuery,
    ) {}

    /**
     * POST /api/lms/attendances/from-video-session
     *
     * Synchronise les présences depuis une session vidéo (BBB/visio) vers ESBTPAttendance.
     */
    public function syncAttendancesFromVideoSession(SyncAttendancesRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        /** @var int $seanceCoursId */
        $seanceCoursId = $request->validated('seance_cours_id');
        /** @var string $date */
        $date = $request->validated('date');
        /** @var array<int, array<string, mixed>> $participants */
        $participants = $request->validated('participants');

        $result = $this->videoSessionSyncer->sync($seanceCoursId, $date, $participants, $user);

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * GET /api/lms/attendance/history
     *
     * Historique de présence (paginé) filtré par rôle (enseignant / étudiant /
     * coordinateur) et par paramètres optionnels (seance_id, date_from, date_to).
     */
    public function getAttendanceHistory(AttendanceHistoryRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->historyQuery->getHistory($request, $user);

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * GET /api/lms/seances/{seanceId}/attendances
     *
     * Présences détaillées d'une séance (lookup local OU klassci_seance_id), avec
     * statistiques agrégées et formatage par participant.
     */
    public function getSeanceAttendances(Request $request, int $seanceId): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->seanceAttendancesQuery->getAttendances($seanceId, $user);

        return response()->json($result['payload'], $result['status']);
    }
}
