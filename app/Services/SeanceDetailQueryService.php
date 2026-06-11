<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Services\Seances\KlassciSeanceLookupService;
use App\Services\Seances\SeanceVisioEnricher;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * SeanceDetailQueryService — single-séance query orchestrator.
 *
 * Extracted from the legacy `SeanceQueryService` (491 lines, §1.1 violation) and
 * the inline `seanceParticipants` block of `LMSSeancesQueryController` (922 lines,
 * §5 violation) during split-1.
 *
 * ## Responsibility (SRP)
 *
 * Queries scoped to **ONE specific séance**:
 *   - `getSeanceDetailsArray()` — full details payload (seance + visio + participants).
 *   - `getProgrammation()` — convenience accessor for the programmation block.
 *   - `getSeanceParticipantsArray()` — participants-only payload (teacher + students).
 *
 * Listing queries (upcoming, my-teaching, my-classes) live in
 * `SeancesListQueryService`. History queries live in `SeancesHistoryQueryService`.
 *
 * ## Architecture
 *
 * This service is intentionally thin (≤300 lines) and delegates to two helpers:
 *   - `KlassciSeanceLookupService` — KLASSCI API role-aware single-séance lookup.
 *   - `SeanceVisioEnricher` — local-DB fallback + visio enrichment + participants.
 *
 * ## Contract
 *
 * - Successful resolution → returns an associative array.
 * - Séance not found → returns `null` (caller renders 404).
 * - Missing KLASSCI token on user → throws `RuntimeException` (caller renders 401).
 * - Unexpected errors propagate up (caller logs and renders 500).
 *
 * @see \App\Http\Controllers\API\LMS\LMSSeanceDetailsController (route handler)
 * @see \App\Http\Controllers\API\LMS\LMSVisioParticipantController (consumes getSeanceDetailsArray)
 * @see \App\Http\Controllers\API\LMS\LMSAttendancesController (consumes getSeanceDetailsArray)
 */
final class SeanceDetailQueryService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KlassciProxyService $klassciService,
        private readonly KlassciSeanceLookupService $klassciLookup,
        private readonly SeanceVisioEnricher $visioEnricher,
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

        $this->logger->info('Récupération détails séance', ['seance_id' => $seanceId]);

        // 1. Récupérer la séance depuis KLASSCI selon le rôle
        [$seance, $matiereInfo] = $this->klassciLookup->lookup($seanceId, $user, $klassciToken);

        // 2. Si la séance n'a pas été trouvée via l'API KLASSCI, essayer la BDD locale
        if (!$seance) {
            $fallback = $this->visioEnricher->loadFromLocalDbFallback($seanceId, $user);
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
        $visioData = $this->visioEnricher->enrichWithVisioData($seance, $seanceId);

        // 5. Construire la fenêtre temporelle de la visio
        $seance['visio_window'] = $this->visioEnricher->buildVisioWindow($heureDebut, $heureFin, $seance, $visioData);

        // 6. Récupérer les participants (teacher + students)
        $teacher = $seance['enseignant'] ?? null;
        $classeId = $seance['classe']['id'] ?? null;
        $students = $this->visioEnricher->resolveParticipants($classeId, $klassciToken, $visioData);

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
        if ($user->isTeacher() || $user->isCoordinator() || $user->isAdmin()) {
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

    /**
     * Returns the participants payload (teacher + students) for a séance.
     *
     * Extracted verbatim from the legacy
     * `LMSSeancesQueryController::seanceParticipants()` inline block. The
     * KLASSCI lookup path is intentionally NOT shared with `getSeanceDetailsArray`
     * because the legacy code did not inject the enseignant on the teacher branch
     * here (only the dashboard matieres are walked, no enseignant attachment).
     * Preserving that for behavioral parity.
     *
     * @return array{seance: array<string, mixed>, teacher: array<string, mixed>, students: array<int, array<string, mixed>>, total_participants: int}|null
     *
     * @throws RuntimeException If the user has no `klassci_token`.
     */
    public function getSeanceParticipantsArray(int $seanceId, User $user): ?array
    {
        $klassciToken = $user->klassci_token;

        if (!$klassciToken) {
            throw new RuntimeException('Token KLASSCI non trouvé');
        }

        $this->logger->info('Récupération participants séance', ['seance_id' => $seanceId]);

        $seance = $this->findSeanceForParticipants($seanceId, $user, $klassciToken);
        if (!$seance) {
            return null;
        }

        $teacher = [
            'id' => $user->klassci_id,
            'nom' => $user->name
        ];
        $classeId = $seance['classe']['id'] ?? null;
        $students = $this->fetchActiveStudents($classeId, $klassciToken);

        return [
            'seance' => $seance,
            'teacher' => $teacher,
            'students' => $students,
            'total_participants' => 1 + count($students)
        ];
    }

    /**
     * Walks the role-appropriate matières list and returns the matching séance
     * (without enseignant injection — legacy parity).
     *
     * @return array<string, mixed>|null
     */
    private function findSeanceForParticipants(int $seanceId, User $user, string $klassciToken): ?array
    {
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

            $seanceTrouvee = collect($matiereDetails['data']['seances_programmees'] ?? [])
                ->firstWhere('id', $seanceId);

            if ($seanceTrouvee) {
                return $seanceTrouvee;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchActiveStudents(?int $classeId, string $klassciToken): array
    {
        if (!$classeId) {
            return [];
        }

        try {
            $etudiantsResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "classes/{$classeId}/etudiants",
                'GET'
            );

            return collect($etudiantsResponse['data'] ?? [])
                ->filter(function ($etudiant) {
                    return isset($etudiant['statut']) && $etudiant['statut'] === 'actif';
                })
                ->values()
                ->toArray();

        } catch (\Exception $e) {
            $this->logger->warning('Erreur récupération étudiants', [
                'classe_id' => $classeId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}
