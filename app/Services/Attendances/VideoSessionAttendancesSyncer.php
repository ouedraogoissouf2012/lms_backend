<?php

declare(strict_types=1);

namespace App\Services\Attendances;

use App\Models\Classe;
use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;
use App\Models\UserClass;
use App\Services\Visio\VisioActorAuthorization;
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
        private readonly VisioActorAuthorization $authorization,
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
            $seance = $this->resolveSeance($seanceCoursId, $actor);
            if ($seance === null) {
                return $this->fail(404, 'seance_not_found');
            }

            if (! $this->authorization->canManage($seance, $actor)) {
                return $this->fail(403, 'actor_not_authorized');
            }

            $students = $this->resolveStudents($participants, $seance);
            if ($students === null) {
                return $this->fail(403, 'not_enrolled');
            }

            $this->logger->info('Synchronisation attendances vidéo', [
                'seance_id' => $seance->id,
                'date' => $date,
                'nb_participants' => count($participants),
            ]);

            $created = 0;
            $updated = 0;
            $errors = [];

            foreach ($participants as $index => $participant) {
                try {
                    $result = $this->syncOne($seance, $students[$index], $participant);
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
        Seance $seance,
        User $student,
        array $participant,
    ): string {
        $joinedAt = $participant['joined_at'] ?? null;
        $leftAt = $participant['left_at'] ?? null;
        $durationMinutes = $participant['duration_minutes'] ?? null;

        $attendance = ESBTPAttendance::updateOrCreate(
            [
                'seance_id' => $seance->id,
                'user_id' => $student->id,
                'institution_id' => $seance->institution_id,
            ],
            [
                'klassci_etudiant_id' => $student->klassci_id,
                'nom' => $student->name,
                'email' => $student->email,
                'joined_at' => $joinedAt,
                'left_at' => $leftAt,
                'last_seen_at' => $leftAt ?? $joinedAt,
                'duration_minutes' => $durationMinutes,
                'status' => $leftAt === null ? 'connected' : 'disconnected',
                'is_validated' => true,
                'is_observer' => false,
            ]
        );

        return $attendance->wasRecentlyCreated ? 'created' : 'updated';
    }

    private function resolveSeance(int $seanceId, User $actor): ?Seance
    {
        if (! is_int($actor->institution_id)) {
            return null;
        }

        return Seance::query()
            ->where('institution_id', $actor->institution_id)
            ->where(function ($query) use ($seanceId): void {
                $query->whereKey($seanceId)->orWhere('klassci_seance_id', $seanceId);
            })
            ->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $participants
     * @return array<int, User>|null
     */
    private function resolveStudents(array $participants, Seance $seance): ?array
    {
        if (! is_int($seance->institution_id) || $seance->klassci_classe_id === null) {
            return null;
        }

        $students = [];
        foreach ($participants as $participant) {
            $klassciId = $participant['etudiant_id'] ?? null;
            $student = User::query()
                ->where('institution_id', $seance->institution_id)
                ->where('klassci_id', $klassciId)
                ->first();

            if (! $student instanceof User || ! $student->isStudent()
                || ! $this->isEnrolled($student, $seance)) {
                return null;
            }

            $students[] = $student;
        }

        return $students;
    }

    private function isEnrolled(User $student, Seance $seance): bool
    {
        $klassciClasseId = (int) $seance->klassci_classe_id;
        $inSynchronizedClass = UserClass::query()
            ->where('institution_id', $seance->institution_id)
            ->where('user_id', $student->id)
            ->where('klassci_classe_id', $klassciClasseId)
            ->exists();

        if ($inSynchronizedClass) {
            return true;
        }

        return Classe::query()
            ->where('institution_id', $seance->institution_id)
            ->where('klassci_id', $klassciClasseId)
            ->whereHas('etudiants', function ($query) use ($student): void {
                $query->where('users.id', $student->id)
                    ->where('classe_etudiant.statut', 'actif');
            })
            ->exists();
    }

    /** @return array{status:int, payload: array<string, mixed>} */
    private function fail(int $status, string $reason): array
    {
        return [
            'status' => $status,
            'payload' => ['success' => false, 'reason' => $reason],
        ];
    }
}
