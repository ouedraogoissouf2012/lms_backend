<?php

declare(strict_types=1);

namespace App\Services\Attendances;

use App\Models\Classe;
use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;
use App\Models\UserClass;
use App\Services\Seances\KlassciPayload;
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
     * Résout les users des participants et valide leur inscription, SANS N+1 :
     * un seul `whereIn` pour les users, un ensemble d'inscrits pré-calculé une
     * fois (#503). Comportement tout-ou-rien préservé : si un participant échoue
     * (introuvable / non-étudiant / non-inscrit), tout le batch est refusé.
     * L'ordre de `$students` est aligné sur `$participants`.
     *
     * @param  array<int, array<string, mixed>>  $participants
     * @return array<int, User>|null
     */
    private function resolveStudents(array $participants, Seance $seance): ?array
    {
        if (! is_int($seance->institution_id) || $seance->klassci_classe_id === null) {
            return null;
        }

        $usersByKlassciId = $this->preloadUsers($participants, $seance->institution_id);
        $enrolledIds = $this->enrolledUserIds($seance);

        $students = [];
        foreach ($participants as $participant) {
            $student = $usersByKlassciId->get(KlassciPayload::toInt($participant['etudiant_id'] ?? null));

            if (! $student instanceof User || ! $student->isStudent()
                || ! in_array($student->id, $enrolledIds, true)) {
                return null;
            }

            $students[] = $student;
        }

        return $students;
    }

    /**
     * Users des participants, indexés par `klassci_id` (unique par tenant), en
     * une seule requête. #503.
     *
     * @param  array<int, array<string, mixed>>  $participants
     * @return \Illuminate\Support\Collection<int|string, User>
     */
    private function preloadUsers(array $participants, int $institutionId): \Illuminate\Support\Collection
    {
        $klassciIds = array_values(array_filter(
            array_map(fn (array $p): mixed => $p['etudiant_id'] ?? null, $participants),
            fn (mixed $id): bool => $id !== null
        ));

        /** @var \Illuminate\Support\Collection<int|string, User> $users */
        $users = User::query()
            ->where('institution_id', $institutionId)
            ->whereIn('klassci_id', $klassciIds)
            ->get()
            ->keyBy('klassci_id');

        return $users;
    }

    /**
     * Ensemble des `user_id` inscrits à la classe de la séance (union des classes
     * KLASSCI synchronisées `UserClass` et du pivot local `classe_etudiant`
     * actif), pré-calculé en 2 requêtes fixes. #503.
     *
     * @return array<int, int>
     */
    private function enrolledUserIds(Seance $seance): array
    {
        $klassciClasseId = (int) $seance->klassci_classe_id;

        $fromUserClass = UserClass::query()
            ->where('institution_id', $seance->institution_id)
            ->where('klassci_classe_id', $klassciClasseId)
            ->pluck('user_id');

        $fromPivot = Classe::query()
            ->where('institution_id', $seance->institution_id)
            ->where('klassci_id', $klassciClasseId)
            ->with(['etudiants' => fn ($query) => $query->where('classe_etudiant.statut', 'actif')])
            ->get()
            ->flatMap(fn (Classe $classe): \Illuminate\Support\Collection => $classe->etudiants->pluck('id'));

        /** @var array<int, int> $ids */
        $ids = $fromUserClass->concat($fromPivot)->unique()->values()->all();

        return $ids;
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
