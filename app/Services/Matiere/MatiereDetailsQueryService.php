<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\User;
use RuntimeException;

/**
 * MatiereDetailsQueryService — orchestrates the matière details payload.
 *
 * Replaces the inline pipeline that used to live in
 * {@see \App\Http\Controllers\API\LMS\LMSMatieresQueryController::matiereDetails}
 * (legacy 472-line method).
 *
 * Pipeline:
 *   1. Resolve matière + combinaisons + enseignants ({@see MatiereInfoFetcher}).
 *   2. Resolve role-scoped séances + enrich them ({@see MatiereSeancesFetcher}).
 *   3. Fetch evaluations (KLASSCI + LMS-only) ({@see MatiereEvaluationsFetcher}).
 *   4. Fetch lessons + compute stats ({@see MatiereLessonsAndStatsBuilder}).
 *
 * Contract preserved from the original controller:
 *   - Missing `klassci_token` on user → throws `RuntimeException` (caller renders 401).
 *   - Matière introuvable → returns `null` (caller renders 404).
 *   - Returns the final `data` array on success.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 (services ≤300 lignes)
 * @see PRODUCTION_STANDARDS.md §1.6 D (DI strict)
 */
final class MatiereDetailsQueryService
{
    public function __construct(
        private readonly MatiereInfoFetcher $infoFetcher,
        private readonly MatiereSeancesFetcher $seancesFetcher,
        private readonly MatiereEvaluationsFetcher $evaluationsFetcher,
        private readonly MatiereLessonsAndStatsBuilder $lessonsAndStatsBuilder,
    ) {}

    /**
     * @return array{matiere: array<string, mixed>, combinaisons: array<int, array<string, mixed>>, enseignants: array<int, array<string, mixed>>, lessons: array<int, array<string, mixed>>, seances_programmees: array<int, array<string, mixed>>, evaluations_programmees: array<int, array<string, mixed>>, statistiques: array<string, mixed>}|null
     */
    public function getDetailsForUser(int $matiereId, User $user): ?array
    {
        $klassciToken = $user->klassci_token;

        if (!$klassciToken) {
            throw new RuntimeException('Token KLASSCI non trouvé');
        }

        // 1. Base matière info + combinaisons + enseignants.
        $info = $this->infoFetcher->fetchMatiereInfo($klassciToken, $matiereId);

        if ($info === null) {
            return null;
        }

        // 2. Séances (role-scoped + filtered + enriched).
        $seancesPayload = $this->seancesFetcher->fetchSeancesForUser(
            $user,
            $matiereId,
            $klassciToken,
            $info['matiereData'],
        );

        // 3. Evaluations (KLASSCI + LMS-only).
        $evaluationsPayload = $this->evaluationsFetcher->fetchEvaluationsForMatiere(
            $klassciToken,
            $matiereId,
            $user,
        );

        // 4. Lessons + stats aggregate.
        $lessonsAndStats = $this->lessonsAndStatsBuilder->buildLessonsAndStats(
            $matiereId,
            $user,
            $seancesPayload['seances'],
            $info['matiere'],
            $info['combinaisons'],
            $info['enseignants'],
            $evaluationsPayload['evaluations_raw_count'],
        );

        return [
            'matiere' => $info['matiere'],
            'combinaisons' => $info['combinaisons'],
            'enseignants' => $info['enseignants'],
            'lessons' => $lessonsAndStats['lessons'],
            'seances_programmees' => $seancesPayload['seances_enrichies'],
            'evaluations_programmees' => $evaluationsPayload['evaluations_enrichies'],
            'statistiques' => $lessonsAndStats['stats'],
        ];
    }
}
