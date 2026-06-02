<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\Lesson;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * MyMatieresQueryService — fetches the teacher dashboard matières + LMS stats.
 *
 * Extracted from {@see \App\Http\Controllers\API\LMS\LMSMatieresQueryController::myMatieres}
 * (legacy lines 510-602).
 *
 * Responsibility:
 *   - Fetches KLASSCI `me/teacher-dashboard` payload.
 *   - For each matière, augments the payload with local LMS counts
 *     (lessons published / draft, séances, evaluations programmées).
 *
 * Contract preserved:
 *   - Missing `klassci_token` → throws `RuntimeException` (caller renders 401).
 *
 * @see PRODUCTION_STANDARDS.md §1.1
 */
final class MyMatieresQueryService
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMatieresForUser(User $user): array
    {
        $klassciToken = $user->klassci_token;

        if (!$klassciToken) {
            throw new RuntimeException('Token KLASSCI non trouvé');
        }

        $this->logger->info('MyMatieres request', [
            'user_id' => $user->id,
            'klassci_id' => $user->klassci_id,
        ]);

        $klassciData = $this->klassciService->requestWithUserToken(
            $klassciToken,
            'me/teacher-dashboard',
            'GET'
        );

        /** @var array<int, array<string, mixed>> $matieres */
        $matieres = $klassciData['data']['matieres'] ?? [];
        /** @var array<int, array<string, mixed>> $evaluations */
        $evaluations = $klassciData['data']['evaluations'] ?? [];

        $matieresEnrichies = array_map(
            fn (array $matiere): array => $this->enrichMatiere($matiere, $evaluations),
            $matieres,
        );

        $this->logger->info('MyMatieres enrichies', [
            'nombre_matieres' => count($matieresEnrichies),
        ]);

        return $matieresEnrichies;
    }

    /**
     * @param  array<string, mixed>  $matiere
     * @param  array<int, array<string, mixed>>  $evaluations
     * @return array<string, mixed>
     */
    private function enrichMatiere(array $matiere, array $evaluations): array
    {
        $matiereId = $matiere['id'] ?? $matiere['matiere_id'] ?? null;

        if ($matiereId === null) {
            return $matiere;
        }

        $nombreLessonsPubliees = Lesson::where('matiere_id', $matiereId)
            ->published()
            ->count();

        $nombreLessonsBrouillons = Lesson::where('matiere_id', $matiereId)
            ->where('status', 'draft')
            ->count();

        $nombreSeances = Seance::where('klassci_matiere_id', $matiereId)->count();

        $nombreEvaluations = collect($evaluations)->filter(function (array $evaluation) use ($matiereId): bool {
            $matiereData = $evaluation['matiere'] ?? null;
            $evalMatiereId = is_array($matiereData)
                ? ($matiereData['id'] ?? $matiereData['matiere_id'] ?? null)
                : ($evaluation['matiere_id'] ?? null);

            return $evalMatiereId == $matiereId;
        })->count();

        $matiere['statistiques'] = [
            'nombre_lessons_publiees' => $nombreLessonsPubliees,
            'nombre_lessons_brouillons' => $nombreLessonsBrouillons,
            'nombre_seances' => $nombreSeances,
            'nombre_evaluations' => $nombreEvaluations,
        ];

        return $matiere;
    }
}
