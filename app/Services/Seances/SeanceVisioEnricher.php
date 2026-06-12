<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\User;
use App\Models\UserClass;
use App\Services\KlassciProxyService;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;

/**
 * SeanceVisioEnricher — local-DB + visio enrichment for séance payloads.
 *
 * Extracted from `SeanceQueryService` (491 lines, §1.1 violation) during split-1.
 *
 * ## Responsibility (SRP)
 *
 * Enrichment helpers that augment a séance array with local data:
 *   - `loadFromLocalDbFallback()` — try to build a séance from the local DB
 *     when KLASSCI did not return it.
 *   - `enrichWithVisioData()` — overlay visio_* fields from the local DB.
 *   - `buildVisioWindow()` — compute the temporal visio window
 *     (can_start / has_started / has_ended / is_in_window / is_accessible).
 *   - `resolveParticipants()` — fetch class students (KLASSCI then local fallback).
 *
 * @see \App\Services\SeanceDetailQueryService (orchestrator)
 */
final class SeanceVisioEnricher
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KlassciProxyService $klassciService,
    ) {}

    /**
     * Builds a séance + matiere payload from the local DB when KLASSCI did not
     * return the séance. Returns null when the séance cannot be located or when
     * a student tries to access an archived séance.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    public function loadFromLocalDbFallback(int $seanceId, User $user): ?array
    {
        $this->logger->info('Séance non trouvée via API KLASSCI, tentative BDD locale', [
            'seance_id' => $seanceId
        ]);

        $visioData = Seance::where('klassci_seance_id', $seanceId)
            ->orWhere('id', $seanceId)
            ->withConnectedParticipantsCount()
            ->first();

        if (!$visioData) {
            return null;
        }

        // IMPORTANT: Bloquer l'accès aux séances archivées pour les étudiants
        if ($user->isStudent() && !$visioData->is_active) {
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

        $this->logger->info('Séance récupérée depuis BDD locale (fallback global)', [
            'seance_id' => $seanceId,
            'matiere' => $matiereInfo['nom']
        ]);

        return [$seance, $matiereInfo];
    }

    /**
     * Overlays visio_* fields from the local DB onto the séance array (by reference).
     *
     * @param array<string, mixed> $seance Modified by reference.
     */
    public function enrichWithVisioData(array &$seance, int $seanceId): ?Seance
    {
        try {
            $visioData = Seance::where('klassci_seance_id', $seanceId)->withConnectedParticipantsCount()->first();

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

            $this->applyVisioDefaults($seance);
            return null;

        } catch (\Exception $e) {
            $this->logger->warning('Erreur accès table seances', ['error' => $e->getMessage()]);
            $this->applyVisioDefaults($seance);
            return null;
        }
    }

    /**
     * @param array<string, mixed> $seance Modified by reference.
     */
    private function applyVisioDefaults(array &$seance): void
    {
        $seance['visio_enabled'] = false;
        $seance['visio_type'] = 'jitsi';
        $seance['visio_room_id'] = null;
        $seance['visio_status'] = null;
        $seance['visio_participants_count'] = 0;
    }

    /**
     * Builds the temporal visio window flags (can_start / is_in_window / etc.).
     *
     * @param array<string, mixed> $seance
     * @return array<string, mixed>
     */
    public function buildVisioWindow(Carbon $heureDebut, Carbon $heureFin, array $seance, ?Seance $visioData): array
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
     * Resolves the class students list. Tries KLASSCI first, then falls back
     * to the local DB if KLASSCI returns nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolveParticipants(?int $classeId, string $klassciToken, ?Seance $visioData): array
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
            $this->logger->warning('Erreur récupération étudiants séance via KLASSCI', [
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
                $this->logger->error('Erreur fallback BDD pour étudiants', [
                    'classe_id' => $visioData->klassci_classe_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $students;
    }
}
