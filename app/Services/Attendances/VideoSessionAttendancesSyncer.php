<?php

declare(strict_types=1);

namespace App\Services\Attendances;

use App\Models\ESBTPAttendance;
use App\Models\User;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * VideoSessionAttendancesSyncer — extrait verbatim de
 * `LMSAttendancesController::syncAttendancesFromVideoSession()`.
 *
 * Synchronise les présences depuis une session vidéo (BBB/visio) vers la table
 * `ESBTPAttendance` via `updateOrCreate` (clé : seance_cours_id + etudiant_id + date).
 *
 * Conserve un historique horodaté dans la colonne `commentaire` à chaque sync,
 * et accumule les erreurs par participant sans interrompre la boucle complète.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict (LoggerInterface, jamais Log facade)
 */
final class VideoSessionAttendancesSyncer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Synchronise un lot de participants vers ESBTPAttendance.
     *
     * @param  array<int, array<string, mixed>>  $participants
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function sync(
        int $seanceCoursId,
        string $date,
        array $participants,
        User $actor,
    ): array {
        try {
            $this->logger->info('Synchronisation attendances vidéo', [
                'seance_cours_id' => $seanceCoursId,
                'date' => $date,
                'nb_participants' => count($participants),
            ]);

            $created = 0;
            $updated = 0;
            $errors = [];

            foreach ($participants as $participant) {
                try {
                    $result = $this->syncOne($seanceCoursId, $date, $participant, $actor);
                    if ($result === 'created') {
                        $created++;
                    } else {
                        $updated++;
                    }
                } catch (Throwable $e) {
                    $this->logger->error('Erreur sync attendance pour étudiant', [
                        'etudiant_id' => $participant['etudiant_id'] ?? null,
                        'error' => $e->getMessage(),
                    ]);

                    $errors[] = [
                        'etudiant_id' => $participant['etudiant_id'] ?? null,
                        'error' => 'Une erreur est survenue.',
                    ];
                }
            }

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Attendances synchronisées avec succès',
                    'data' => [
                        'created' => $created,
                        'updated' => $updated,
                        'errors' => $errors,
                    ],
                ],
            ];

        } catch (Throwable $e) {
            $this->logger->error('Erreur synchronisation attendances vidéo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de la synchronisation des attendances',
                    'error' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $participant
     * @return 'created'|'updated'
     */
    private function syncOne(
        int $seanceCoursId,
        string $date,
        array $participant,
        User $actor,
    ): string {
        $etudiantId = $participant['etudiant_id'];
        $statut = $participant['statut'];
        $joinedAt = $participant['joined_at'] ?? null;
        $leftAt = $participant['left_at'] ?? null;
        $durationMinutes = $participant['duration_minutes'] ?? null;

        $existingAttendance = ESBTPAttendance::where('seance_cours_id', $seanceCoursId)
            ->where('etudiant_id', $etudiantId)
            ->where('date', $date)
            ->first();

        $commentaireHistorique = $existingAttendance ? ($existingAttendance->commentaire ?? '') : '';
        $newCommentaire = $commentaireHistorique .
            "\n[" . now()->format('Y-m-d H:i:s') . "] Sync vidéo: " .
            ($joinedAt ? "rejoint {$joinedAt}" : '') .
            ($leftAt ? ", quitté {$leftAt}" : '') .
            ($durationMinutes ? ", durée {$durationMinutes}min" : '');

        $attendance = ESBTPAttendance::updateOrCreate(
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
                'updated_by' => $actor->id,
            ]
        );

        return $attendance->wasRecentlyCreated ? 'created' : 'updated';
    }
}
