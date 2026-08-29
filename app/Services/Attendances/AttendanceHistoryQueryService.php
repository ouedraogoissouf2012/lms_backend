<?php

declare(strict_types=1);

namespace App\Services\Attendances;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;
use App\Services\SeanceDetailQueryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * AttendanceHistoryQueryService — extrait verbatim de
 * `LMSAttendancesController::getAttendanceHistory()`.
 *
 * Récupère l'historique de présence d'un utilisateur (filtré par classe / matière /
 * dates), enrichi avec les métadonnées KLASSCI de la séance lorsqu'elles sont
 * disponibles. Le scope est filtré par rôle :
 *   - Enseignant : uniquement les présences de SES séances.
 *   - Étudiant   : uniquement ses propres présences.
 *   - Autres     : pas de filtre (coordinateur / superAdmin).
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 */
final class AttendanceHistoryQueryService
{
    public function __construct(
        private readonly SeanceDetailQueryService $seanceQuery,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Retourne l'historique de présence paginé pour l'utilisateur courant.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function getHistory(Request $request, User $user): array
    {
        try {
            $query = $this->buildBaseQuery($user);
            $this->applyOptionalFilters($query, $request);

            $perPageInput = $request->input('per_page', 50);
            $perPage = is_numeric($perPageInput) ? (int) $perPageInput : 50;
            $attendances = $query->paginate($perPage);

            $enrichedData = $attendances->getCollection()->map(
                fn ($attendance) => $this->formatAttendance($attendance, $user),
            );

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'data' => $enrichedData,
                    'pagination' => [
                        'current_page' => $attendances->currentPage(),
                        'per_page' => $attendances->perPage(),
                        'total' => $attendances->total(),
                        'last_page' => $attendances->lastPage(),
                    ],
                ],
            ];

        } catch (Throwable $e) {
            $this->logger->error('Erreur récupération historique présences', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de la récupération de l\'historique',
                    'error' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * @return Builder<ESBTPAttendance>
     */
    private function buildBaseQuery(User $user): Builder
    {
        $query = ESBTPAttendance::with(['user:id,name,email', 'seance'])
            ->orderBy('joined_at', 'desc');

        if ($user->isTeacher()) {
            // L'enseignant ne voit que les présences de ses propres séances
            $query->whereHas('seance', function ($q) use ($user) {
                $q->whereRaw('enseignant_id = ?', [$user->id]);
            });
        } elseif ($user->isStudent()) {
            // L'étudiant ne voit que ses propres présences
            $query->where('user_id', $user->id);
        }
        // Les coordinateurs/superAdmin voient tout

        return $query;
    }

    /**
     * @param  Builder<ESBTPAttendance>  $query
     */
    private function applyOptionalFilters(Builder $query, Request $request): void
    {
        $seanceId = $request->input('seance_id');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        if (is_scalar($seanceId) && $seanceId !== '') {
            $seanceLocal = Seance::where('klassci_seance_id', $seanceId)->first();
            if ($seanceLocal) {
                $query->where('seance_id', $seanceLocal->id);
            }
        }

        if (is_scalar($dateFrom) && $dateFrom !== '') {
            $query->where('joined_at', '>=', $dateFrom);
        }

        if (is_scalar($dateTo) && $dateTo !== '') {
            $query->where('joined_at', '<=', $dateTo.' 23:59:59');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAttendance(ESBTPAttendance $attendance, User $user): array
    {
        $attendanceUser = $attendance->getRelationValue('user');
        $attendanceSeance = $attendance->getRelationValue('seance');
        $relatedUser = $attendanceUser instanceof User ? $attendanceUser : null;
        $relatedSeance = $attendanceSeance instanceof Seance ? $attendanceSeance : null;

        $data = [
            'id' => $attendance->id,
            'user' => [
                'id' => $relatedUser instanceof User ? $relatedUser->id : 0,
                'name' => $relatedUser instanceof User ? $relatedUser->name : 'Utilisateur supprimé',
                'email' => $relatedUser instanceof User ? $relatedUser->email : '',
            ],
            'seance' => [
                'id' => $relatedSeance instanceof Seance ? $relatedSeance->id : 0,
                'klassci_seance_id' => $relatedSeance instanceof Seance ? $relatedSeance->klassci_seance_id : 'N/A',
                'date' => $relatedSeance instanceof Seance ? $relatedSeance->date_seance : null,
                'matiere' => null,
                'classe' => null,
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
        if ($relatedSeance !== null && $relatedSeance->klassci_seance_id) {
            $this->enrichWithKlassciDetails($data, $relatedSeance, $user);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function enrichWithKlassciDetails(array &$data, Seance $seance, User $user): void
    {
        try {
            // Use SeanceDetailQueryService (split-1, ex-SeanceQueryService PR E) instead of legacy
            // `$this->seanceDetails($id, $request)` + json_decode anti-pattern.
            // Returns the seance array directly — no encode/decode round-trip.
            $seanceArray = $this->seanceQuery->getSeanceDetailsArray(
                $seance->klassci_seance_id,
                $user,
            );

            if ($seanceArray !== null) {
                $klassciSeance = $seanceArray['seance'];
                $localSeance = $data['seance'] ?? [];

                if (is_array($localSeance)) {
                    $localSeance['matiere'] = $klassciSeance['matiere'] ?? null;
                    $localSeance['classe'] = $klassciSeance['classe'] ?? null;
                    $localSeance['programmation'] = $klassciSeance['programmation'] ?? null;
                    $data['seance'] = $localSeance;
                }
            }
        } catch (Throwable $e) {
            // Ignorer les erreurs de récupération KLASSCI (séance peut être archivée)
            $this->logger->debug('Impossible de récupérer détails KLASSCI pour historique', [
                'seance_id' => $seance->klassci_seance_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
