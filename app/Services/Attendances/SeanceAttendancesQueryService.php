<?php

declare(strict_types=1);

namespace App\Services\Attendances;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;
use App\Services\Visio\VisioActorAuthorization;
use App\Support\Logging\CaughtExceptionLog;
use Throwable;

/**
 * SeanceAttendancesQueryService — extrait verbatim de
 * `LMSAttendancesController::getSeanceAttendances()`.
 *
 * Récupère les présences d'une séance (lookup par ID local OU klassci_seance_id),
 * applique la règle d'accès du détail de séance (#1078, `VisioActorAuthorization`),
 * puis calcule :
 *   - les statistiques globales, par {@see StatistiquesDesPresences} (#1649),
 *   - le formatage par participant (status_level, presence_status, % participation,
 *     gestion observateurs).
 *
 * Une séance d'école KLASSCI sans ligne locale est décrite par
 * {@see SeanceSansLigneLocale} : elle existe, et n'a aucune présence (#1098).
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 */
final class SeanceAttendancesQueryService
{
    public function __construct(
        private readonly CaughtExceptionLog $journal,
        private readonly VisioActorAuthorization $acces,
        private readonly StatistiquesDesPresences $statistiques,
    ) {}

    /**
     * Retourne les présences d'une séance (+ statistiques + métadonnées).
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function getAttendances(int $seanceId, User $user, SeanceSansLigneLocale $sansLigne): array
    {
        try {
            $seance = $this->findSeance($seanceId);
            if (! $seance) {
                // L'ordre de recherche des lignes est inchangé : la séance sans ligne
                // n'est cherchée que si AUCUNE ligne ne répond (#1098).
                $decrite = $sansLigne->decrire($seanceId, $user);

                return $decrite === null
                    ? ['status' => 404, 'payload' => ['success' => false, 'message' => 'Séance non trouvée']]
                    : ['status' => 200, 'payload' => $this->sansPresence($decrite)];
            }

            $accessError = $this->checkAccess($seance, $user);
            if ($accessError !== null) {
                return $accessError;
            }

            $seanceDurationMinutes = $this->computeSeanceDuration($seance);
            $seanceData = $this->buildSeanceData($seance, $seanceDurationMinutes);

            $allAttendances = ESBTPAttendance::where('seance_id', $seance->id)
                ->orderBy('joined_at', 'asc')
                ->get();

            $statistics = $this->statistiques->de($allAttendances);
            $formattedAttendances = $allAttendances->map(
                fn ($att) => $this->formatAttendance($att, $seanceDurationMinutes),
            );

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'seance' => $seanceData,
                    'statistics' => $statistics,
                    'attendances' => $formattedAttendances->values(),
                ],
            ];

        } catch (Throwable $e) {
            $this->journal->error('Erreur récupération attendances séance', $e, [
                'seance_id' => $seanceId,
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de la récupération des présences',
                    'error' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    private function findSeance(int $seanceId): ?Seance
    {
        // Chercher par ID local d'abord, puis par klassci_seance_id
        $seance = Seance::find($seanceId);
        if (! $seance) {
            $seance = Seance::where('klassci_seance_id', $seanceId)->first();
        }

        return $seance;
    }

    /**
     * @return array{status:int, payload: array<string, mixed>}|null
     */
    private function checkAccess(Seance $seance, User $user): ?array
    {
        // La règle du détail de séance (#1078) : la gestion de l'école, ou l'auteur.
        if (! $this->acces->canManage($seance, $user)) {
            return [
                'status' => 403,
                'payload' => [
                    'success' => false,
                    'message' => 'Accès refusé. Cette séance ne vous appartient pas.',
                ],
            ];
        }

        return null;
    }

    /**
     * Une séance qui existe sans ligne locale n'a, par construction, aucune présence.
     *
     * @param  array{klassci_seance_id: int, enseignant_nom: string|null, matiere_nom: string|null, date: string|null}  $decrite
     * @return array<string, mixed>
     */
    private function sansPresence(array $decrite): array
    {
        return [
            'success' => true,
            'seance' => [
                'id' => null,
                'klassci_seance_id' => $decrite['klassci_seance_id'],
                'enseignant_nom' => $decrite['enseignant_nom'],
                'coordinateur_nom' => null,
                'visio_started_at' => null,
                'visio_ended_at' => null,
                'duration_minutes' => null,
                'is_finished' => false,
                'matiere_nom' => $decrite['matiere_nom'] ?? 'Matière inconnue',
                'date' => $decrite['date'],
            ],
            'statistics' => ['total_participants' => 0, 'average_duration' => 0, 'presence_rate' => 0],
            'attendances' => [],
        ];
    }

    private function computeSeanceDuration(Seance $seance): ?int
    {
        if ($seance->visio_started_at && $seance->visio_ended_at) {
            $diffMinutes = $seance->visio_started_at->diffInMinutes($seance->visio_ended_at);
            if ($diffMinutes <= 1440) {
                return (int) $diffMinutes;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSeanceData(Seance $seance, ?int $seanceDurationMinutes): array
    {
        $isFinished = $seance->visio_ended_at !== null;

        return [
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
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAttendance(ESBTPAttendance $att, ?int $seanceDurationMinutes): array
    {
        $durationMinutes = $att->duration_minutes ?? 0;

        // Pourcentage de participation par rapport à la durée de la séance
        $participationPercentage = 0;
        if ($seanceDurationMinutes && $seanceDurationMinutes > 0) {
            $participationPercentage = min(100, (int) round(($durationMinutes / $seanceDurationMinutes) * 100));
        }

        [$statusLevel, $presenceStatus] = $this->resolvePresenceLabel(
            $durationMinutes,
            $participationPercentage,
            (bool) $att->is_observer,
        );

        $fullName = trim(($att->prenom ?? '').' '.($att->nom ?? ''));
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
            // #1649 : la marque de l'appel et son origine, nulles sans appel.
            'mark' => $att->mark?->value,
            'origin' => $att->origin?->value,
        ];
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function resolvePresenceLabel(int $durationMinutes, int $participationPercentage, bool $isObserver): array
    {
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

        // Observateurs (override)
        if ($isObserver) {
            $statusLevel = 'info';
            $presenceStatus = 'Observateur';
        }

        return [$statusLevel, $presenceStatus];
    }
}
