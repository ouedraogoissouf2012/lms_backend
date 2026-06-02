<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\Lesson;
use App\Models\User;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MatiereLessonsAndStatsBuilder — fetches LMS lessons + builds matière stats.
 *
 * Extracted from {@see \App\Http\Controllers\API\LMS\LMSMatieresQueryController::matiereDetails}
 * (legacy lines 351-394 + 441-460).
 *
 * Responsibility:
 *   - Loads `Lesson` rows for the matière, scoped by role
 *     (students see only published lessons + their progress; others see all).
 *   - Computes the aggregate statistics block returned with the matière.
 *
 * @see PRODUCTION_STANDARDS.md §1.1
 */
final class MatiereLessonsAndStatsBuilder
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $seances
     * @param  array<string, mixed>  $matiere
     * @param  array<int, array<string, mixed>>  $combinaisons
     * @param  array<int, array<string, mixed>>  $enseignants
     * @return array{lessons: array<int, array<string, mixed>>, stats: array<string, mixed>}
     */
    public function buildLessonsAndStats(
        int $matiereId,
        User $user,
        array $seances,
        array $matiere,
        array $combinaisons,
        array $enseignants,
        int $evaluationsCount,
    ): array {
        $lessons = $this->fetchLessons($matiereId, $user);

        $stats = $this->computeStats(
            $seances,
            $lessons,
            $matiere,
            $combinaisons,
            $enseignants,
            $evaluationsCount,
        );

        return [
            'lessons' => $lessons,
            'stats' => $stats,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLessons(int $matiereId, User $user): array
    {
        try {
            $query = Lesson::where('matiere_id', $matiereId);

            if ($user->isStudent()) {
                $query->published();

                // PERF-03 — eager load `progress` filtered to current user.
                $query->with(['progress' => function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                }]);
            }

            $lessons = $query->ordered()
                ->get()
                ->map(function (Lesson $lesson) use ($user): array {
                    $lessonArray = $lesson->toArray();

                    if ($user->isStudent()) {
                        // progress is filtered to the current user, so 0 or 1 entry.
                        $lessonArray['user_progress'] = $lesson->progress->first();
                    }

                    return $lessonArray;
                })->all();

            $this->logger->info('Lessons LMS récupérés', [
                'matiere_id' => $matiereId,
                'count' => count($lessons),
            ]);

            return $lessons;
        } catch (Throwable $e) {
            $this->logger->warning('Erreur récupération lessons LMS', [
                'matiere_id' => $matiereId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $seances
     * @param  array<int, array<string, mixed>>  $lessons
     * @param  array<string, mixed>  $matiere
     * @param  array<int, array<string, mixed>>  $combinaisons
     * @param  array<int, array<string, mixed>>  $enseignants
     * @return array<string, mixed>
     */
    private function computeStats(
        array $seances,
        array $lessons,
        array $matiere,
        array $combinaisons,
        array $enseignants,
        int $evaluationsCount,
    ): array {
        $seancesCollection = collect($seances);
        $seancesRealisees = $seancesCollection->filter(function (array $seance): bool {
            return isset($seance['statut']) && $seance['statut'] === 'realise';
        });

        return [
            'nombre_seances_programmees' => count($seances),
            'nombre_seances_realisees' => $seancesRealisees->count(),
            'taux_realisation' => count($seances) > 0
                ? round(($seancesRealisees->count() / count($seances)) * 100, 2)
                : 0,
            'nombre_evaluations' => $evaluationsCount,
            'nombre_enseignants' => count($enseignants),
            'nombre_combinaisons' => count($combinaisons),
            'nombre_lessons' => count($lessons),
            'volume_horaire_total' => $matiere['volume_horaire_total'] ?? null,
            'coefficient' => $matiere['coefficient'] ?? null,
        ];
    }
}
