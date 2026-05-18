<?php

namespace App\Services;

use App\Models\Seance;
use App\Models\User;
use App\Models\UserClass;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * SeanceQueryService — query and build structured arrays for séance details.
 *
 * Created during the `LMSDataController` god-object split (spec:
 * `.claude/specs/lms-data-controller-split/`, PR E).
 *
 * ## Why this service exists
 *
 * The legacy `LMSDataController::seanceDetails()` was a public route handler,
 * but it was ALSO called as a helper from `getVisioParticipants` (×2) and
 * `getAttendanceHistory` via `json_decode($response->getContent())`. That pattern
 * was a triple anti-pattern:
 *   1. DRY violation — business logic dispersed in HTTP return values.
 *   2. Performance waste — JSON encode + decode round-trip on every call.
 *   3. Controller-to-controller coupling that breaks when controllers are split.
 *
 * This service extracts the séance-fetching business logic to a unit that:
 *   - Returns native arrays (no JSON encode/decode round-trip).
 *   - Is injectable into multiple controllers (`LMSSeancesController` in PR F,
 *     `LMSVisioController` in PR I, `LMSAttendancesController` in PR H).
 *   - Can be tested in isolation with mocked `KlassciProxyService`.
 *
 * ## Contract
 *
 * - Successful resolution → returns an associative array with the séance
 *   structure (seance + visio + participants on success).
 * - Séance not found anywhere → returns `null` (caller renders 404).
 * - Missing KLASSCI token on user → throws `RuntimeException` (caller renders 401).
 * - Unexpected errors propagate up (caller logs and renders 500).
 *
 * @see \App\Http\Controllers\API\LMS\LMSSeancesController::seanceDetails (route handler, delegates here)
 * @see .claude/specs/lms-data-controller-split/design.md §2.2
 */
final class SeanceQueryService
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
    ) {}

    /**
     * Returns the structured seance details array for an authenticated user.
     *
     * The return shape mirrors the legacy `seanceDetails()` `data` payload to
     * preserve the HTTP contract when callers wrap it as JSON.
     *
     * @return array{seance: array<string, mixed>, visio: array<string, mixed>, participants?: array<string, mixed>}|null
     *
     * @throws RuntimeException If the user has no `klassci_token`.
     */
    public function getSeanceDetailsArray(int $seanceId, User $user): ?array
    {
        $klassciToken = $user->klassci_token;

        if (!$klassciToken) {
            throw new RuntimeException('Token KLASSCI non trouvé');
        }

        Log::info('Récupération détails séance', ['seance_id' => $seanceId]);

        // 1. Récupérer la séance depuis KLASSCI selon le rôle
        [$seance, $matiereInfo] = $this->resolveSeanceFromKlassci($seanceId, $user, $klassciToken);

        // 2. Si la séance n'a pas été trouvée via l'API KLASSCI, essayer la BDD locale
        if (!$seance) {
            $fallback = $this->resolveSeanceFromLocalDb($seanceId, $user);
            if ($fallback === null) {
                return null; // 404
            }
            [$seance, $matiereInfo] = $fallback;
        }

        // 3. Enrichir avec durée calculée
        $heureDebut = Carbon::parse($seance['programmation']['heure_debut']);
        $heureFin = Carbon::parse($seance['programmation']['heure_fin']);
        $seance['duree_minutes'] = $heureDebut->diffInMinutes($heureFin);

        // 4. Enrichir avec données visio depuis BDD locale
        $visioData = $this->enrichWithVisioData($seance, $seanceId);

        // 5. Construire la fenêtre temporelle de la visio
        $seance['visio_window'] = $this->buildVisioWindow($heureDebut, $heureFin, $seance, $visioData);

        // 6. Récupérer les participants (teacher + students)
        $teacher = $seance['enseignant'] ?? null;
        $classeId = $seance['classe']['id'] ?? null;
        $students = $this->resolveParticipants($classeId, $klassciToken, $visioData);

        // 7. Ajouter la matière à la séance si disponible
        if ($matiereInfo) {
            $seance['matiere'] = [
                'id' => $matiereInfo['id'],
                'nom' => $matiereInfo['nom'] ?? $matiereInfo['libelle'] ?? 'N/A',
                'code' => $matiereInfo['code'] ?? null
            ];
        }

        // 8. Préparer la réponse
        $response = [
            'seance' => $seance,
            'visio' => [
                'enabled' => $seance['visio_enabled'],
                'type' => $seance['visio_type'],
                'room_id' => $seance['visio_room_id'],
                'status' => $seance['visio_status'],
                'window' => $seance['visio_window']
            ]
        ];

        // 9. Les participants sont visibles QUE pour les enseignants et coordinateurs
        if (in_array($user->role, ['enseignant', 'teacher', 'coordinateur', 'superAdmin'], true)) {
            $response['participants'] = [
                'teacher' => $teacher,
                'students' => $students,
                'total' => 1 + count($students)
            ];
        }

        return $response;
    }

    /**
     * Returns the `programmation` block (heure_debut, heure_fin, salle) of a seance,
     * or null if unavailable.
     *
     * Used by callers that need timestamp calculations without the full details
     * payload (e.g. attendance status, late-arrival detection).
     *
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException If the user has no `klassci_token`.
     */
    public function getProgrammation(int $seanceId, User $user): ?array
    {
        $details = $this->getSeanceDetailsArray($seanceId, $user);
        if ($details === null) {
            return null;
        }

        $programmation = $details['seance']['programmation'] ?? null;
        return is_array($programmation) ? $programmation : null;
    }

    // ============================================================
    // Private helpers (extracted from legacy logic for readability)
    // ============================================================

    /**
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function resolveSeanceFromKlassci(int $seanceId, User $user, string $klassciToken): array
    {
        if (in_array($user->role, ['enseignant', 'teacher'], true)) {
            return $this->resolveSeanceForTeacher($seanceId, $user, $klassciToken);
        }

        if ($user->role === 'etudiant') {
            return $this->resolveSeanceForStudent($seanceId, $user, $klassciToken);
        }

        // Coordinateur ou supradmin: parcourir toutes les matières
        return $this->resolveSeanceForCoordinator($seanceId, $klassciToken);
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function resolveSeanceForTeacher(int $seanceId, User $user, string $klassciToken): array
    {
        $dashboard = $this->klassciService->requestWithUserToken(
            $klassciToken,
            'me/teacher-dashboard',
            'GET'
        );

        foreach ($dashboard['data']['matieres'] ?? [] as $matiere) {
            $matiereDetails = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "matieres/{$matiere['id']}",
                'GET'
            );

            $seanceTrouvee = collect($matiereDetails['data']['seances_programmees'] ?? [])
                ->firstWhere('id', $seanceId);

            if ($seanceTrouvee) {
                $matiereInfo = $matiereDetails['data']['matiere'] ?? $matiere;
                $seanceTrouvee['enseignant'] = [
                    'id' => $user->klassci_id,
                    'nom' => $user->name,
                    'email' => $user->email
                ];
                return [$seanceTrouvee, $matiereInfo];
            }
        }

        return [null, null];
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function resolveSeanceForStudent(int $seanceId, User $user, string $klassciToken): array
    {
        try {
            $dashboard = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'me/dashboard',
                'GET'
            );

            foreach ($dashboard['data']['cours'] ?? [] as $matiere) {
                $matiereId = $matiere['id'] ?? $matiere['matiere_id'] ?? $matiere['matiere']['id'] ?? null;
                if (!$matiereId) {
                    continue;
                }

                $matiereDetails = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "matieres/{$matiereId}",
                    'GET'
                );

                $seanceTrouvee = collect($matiereDetails['data']['seances_programmees'] ?? [])
                    ->firstWhere('id', $seanceId);

                if ($seanceTrouvee) {
                    $matiereInfo = $matiereDetails['data']['matiere'] ?? $matiere;

                    // L'API KLASSCI ne retourne pas l'enseignant dans seances_programmees;
                    // le récupérer depuis matiereDetails ou matiereInfo.
                    $enseignants = $matiereDetails['data']['enseignants'] ?? [];
                    if (empty($enseignants) && isset($matiereInfo['enseignant'])) {
                        $seanceTrouvee['enseignant'] = $matiereInfo['enseignant'];
                    } elseif (!empty($enseignants)) {
                        $seanceTrouvee['enseignant'] = $enseignants[0];
                    }

                    return [$seanceTrouvee, $matiereInfo];
                }
            }

            return [null, null];

        } catch (\Exception $e) {
            Log::error('Erreur récupération séance étudiant via API KLASSCI', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage()
            ]);
            return [null, null];
        }
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function resolveSeanceForCoordinator(int $seanceId, string $klassciToken): array
    {
        $matieresResponse = $this->klassciService->requestWithUserToken(
            $klassciToken,
            'matieres',
            'GET'
        );

        foreach ($matieresResponse['data'] ?? [] as $matiere) {
            $matiereDetails = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "matieres/{$matiere['id']}",
                'GET'
            );

            $seanceTrouvee = collect($matiereDetails['data']['seances_programmees'] ?? [])
                ->firstWhere('id', $seanceId);

            if ($seanceTrouvee) {
                $matiereInfo = $matiereDetails['data']['matiere'] ?? $matiere;
                return [$seanceTrouvee, $matiereInfo];
            }
        }

        return [null, null];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private function resolveSeanceFromLocalDb(int $seanceId, User $user): ?array
    {
        Log::info('Séance non trouvée via API KLASSCI, tentative BDD locale', [
            'seance_id' => $seanceId
        ]);

        $visioData = Seance::where('klassci_seance_id', $seanceId)
            ->orWhere('id', $seanceId)
            ->first();

        if (!$visioData) {
            return null;
        }

        // IMPORTANT: Bloquer l'accès aux séances archivées pour les étudiants
        if ($user->role === 'etudiant' && !$visioData->is_active) {
            return null;
        }

        // Construire la séance depuis la BDD locale
        $dateSeance = now()->format('Y-m-d');
        $seance = [
            'id' => $visioData->klassci_seance_id ?? $visioData->id,
            'classe' => [
                'id' => $visioData->klassci_classe_id ?? null,
                'nom' => 'B2 COM'
            ],
            'programmation' => [
                'date' => $dateSeance,
                'heure_debut' => $dateSeance . 'T08:00:00+00:00',
                'heure_fin' => $dateSeance . 'T10:00:00+00:00',
                'salle' => 'TEAM'
            ],
            'enseignant' => [
                'nom' => $visioData->enseignant_nom ?? 'Non assigné',
                'prenom' => ''
            ],
            'matiere' => [
                'id' => $visioData->klassci_matiere_id ?? 1,
                'nom' => $visioData->matiere_nom ?? 'Matière',
                'code' => null
            ],
            'visio_enabled' => $visioData->visio_enabled ?? false,
            'visio_type' => $visioData->visio_type ?? 'jitsi',
            'visio_room_id' => $visioData->visio_room_id,
            'visio_status' => $visioData->visio_status,
            'visio_participants_count' => $visioData->current_participants_count ?? 0,
            'duree_minutes' => 120,
            'statut' => 'programme'
        ];

        $matiereInfo = $seance['matiere'];

        Log::info('Séance récupérée depuis BDD locale (fallback global)', [
            'seance_id' => $seanceId,
            'matiere' => $matiereInfo['nom']
        ]);

        return [$seance, $matiereInfo];
    }

    private function enrichWithVisioData(array &$seance, int $seanceId): ?Seance
    {
        try {
            $visioData = Seance::where('klassci_seance_id', $seanceId)->first();

            if ($visioData) {
                $seance['visio_enabled'] = $visioData->visio_enabled ?? false;
                $seance['visio_type'] = $visioData->visio_type ?? 'jitsi';
                $seance['visio_room_id'] = $visioData->visio_room_id;
                $seance['visio_status'] = $visioData->visio_status;
                $seance['visio_participants_count'] = $visioData->current_participants_count ?? 0;

                // L'enseignant en BDD locale a priorité (l'API ne le retourne pas toujours)
                if ($visioData->enseignant_nom) {
                    $seance['enseignant'] = [
                        'nom' => $visioData->enseignant_nom,
                        'prenom' => $visioData->enseignant_prenom ?? ''
                    ];
                }

                return $visioData;
            }

            $seance['visio_enabled'] = false;
            $seance['visio_type'] = 'jitsi';
            $seance['visio_room_id'] = null;
            $seance['visio_status'] = null;
            $seance['visio_participants_count'] = 0;
            return null;

        } catch (\Exception $e) {
            Log::warning('Erreur accès table seances', ['error' => $e->getMessage()]);
            $seance['visio_enabled'] = false;
            $seance['visio_type'] = 'jitsi';
            $seance['visio_room_id'] = null;
            $seance['visio_status'] = null;
            $seance['visio_participants_count'] = 0;
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVisioWindow(Carbon $heureDebut, Carbon $heureFin, array $seance, ?Seance $visioData): array
    {
        $now = Carbon::now();
        $canStart = $now->greaterThanOrEqualTo($heureDebut->copy()->subMinutes(15));
        $canStillStart = $now->lessThanOrEqualTo($heureFin->copy()->addMinutes(30));

        // Visio active ET dans le timeout (4h max après démarrage)
        $visioIsActive = ($seance['visio_status'] === 'active');
        $visioAccessible = false;

        if ($visioIsActive && $visioData && $visioData->visio_started_at) {
            $visioStarted = Carbon::parse($visioData->visio_started_at);
            $visioTimeout = $visioStarted->copy()->addHours(4);
            $visioAccessible = $now->lessThan($visioTimeout);
        }

        return [
            'can_start' => $canStart && $canStillStart,
            'has_started' => $now->greaterThanOrEqualTo($heureDebut),
            'has_ended' => $now->greaterThan($heureFin),
            'is_in_window' => $canStart && !$now->greaterThan($heureFin),
            'is_accessible' => $visioAccessible || ($canStart && !$now->greaterThan($heureFin)),
            'start_window' => $heureDebut->copy()->subMinutes(15)->toIso8601String(),
            'end_window' => $heureFin->copy()->addMinutes(30)->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveParticipants(?int $classeId, string $klassciToken, ?Seance $visioData): array
    {
        if (!$classeId) {
            return [];
        }

        $students = [];

        try {
            $etudiantsResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "classes/{$classeId}/etudiants",
                'GET'
            );

            $students = collect($etudiantsResponse['data'] ?? [])
                ->filter(function (array $etudiant): bool {
                    return isset($etudiant['statut']) && $etudiant['statut'] === 'actif';
                })
                ->values()
                ->toArray();

        } catch (\Exception $e) {
            Log::warning('Erreur récupération étudiants séance via KLASSCI', [
                'classe_id' => $classeId,
                'error' => $e->getMessage()
            ]);
        }

        // Fallback BDD locale si pas d'étudiants via KLASSCI
        if (empty($students) && $visioData && $visioData->klassci_classe_id) {
            try {
                $localStudents = UserClass::where('klassci_classe_id', $visioData->klassci_classe_id)
                    ->join('users', 'user_classes.user_id', '=', 'users.id')
                    ->where('users.role', 'etudiant')
                    ->select('users.id', 'users.name', 'users.email', 'users.klassci_id')
                    ->get();

                $students = $localStudents->map(function ($student): array {
                    $nameParts = explode(' ', $student->name, 2);
                    return [
                        'id' => $student->id,
                        'nom' => $nameParts[0] ?? $student->name,
                        'prenom' => $nameParts[1] ?? '',
                        'email' => $student->email,
                        'klassci_id' => $student->klassci_id,
                        'statut' => 'actif'
                    ];
                })->toArray();
            } catch (\Exception $e) {
                Log::error('Erreur fallback BDD pour étudiants', [
                    'classe_id' => $visioData->klassci_classe_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $students;
    }
}
