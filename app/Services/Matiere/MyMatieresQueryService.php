<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciPayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
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
 * ## Batching (#546)
 *
 * Le compte `nombre_evaluations` est déjà calculé en mémoire (payload KLASSCI
 * déjà chargé). Les 3 autres compteurs (leçons publiées/brouillons, séances)
 * faisaient auparavant 3 requêtes **par matière** ; `preloadStats()` les
 * agrège désormais en 3 requêtes `whereIn`/`groupBy` **pour toutes les
 * matières**, indépendamment de leur nombre.
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

        $matiereIds = array_values(array_filter(array_map(
            fn (array $m): ?int => KlassciPayload::toInt($m['id'] ?? $m['matiere_id'] ?? null),
            $matieres,
        )));
        $stats = $this->preloadStats($matiereIds);

        $matieresEnrichies = array_map(
            fn (array $matiere): array => $this->enrichMatiere($matiere, $evaluations, $stats),
            $matieres,
        );

        $this->logger->info('MyMatieres enrichies', [
            'nombre_matieres' => count($matieresEnrichies),
        ]);

        return $matieresEnrichies;
    }

    /**
     * Compteurs leçons publiées / brouillons / séances pour toutes les
     * matières en 3 requêtes agrégées, indexées par `matiere_id` (#546).
     *
     * @param  array<int, int>  $matiereIds
     * @return array{published: Collection<int, int>, draft: Collection<int, int>, seances: Collection<int, int>}
     */
    private function preloadStats(array $matiereIds): array
    {
        if ($matiereIds === []) {
            return ['published' => collect(), 'draft' => collect(), 'seances' => collect()];
        }

        $published = $this->groupedCounts(
            Lesson::whereIn('matiere_id', $matiereIds)->published(),
            'matiere_id',
        );
        $draft = $this->groupedCounts(
            Lesson::whereIn('matiere_id', $matiereIds)->where('status', LessonStatus::Draft->value),
            'matiere_id',
        );
        $seances = $this->groupedCounts(
            Seance::whereIn('klassci_matiere_id', $matiereIds),
            'klassci_matiere_id',
        );

        return compact('published', 'draft', 'seances');
    }

    /**
     * `GROUP BY <colonne>` matérialisé en `Collection<int,int>` typé. `pluck()`
     * rejette l'alias `cnt` (colonne hors modèle, cf. larastan) : on passe donc
     * par `get()->mapWithKeys()`, même contournement que
     * `ActivityTrendsService::pluckDailyCounts`. Whitelist de `$groupColumn`
     * en défense en profondeur (tous les appelants passent un littéral, mais
     * aucune évolution future ne doit pouvoir y router un input utilisateur).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Collection<int, int>
     */
    private function groupedCounts(Builder $query, string $groupColumn): Collection
    {
        $allowedColumns = ['matiere_id', 'klassci_matiere_id'];
        if (!in_array($groupColumn, $allowedColumns, true)) {
            throw new InvalidArgumentException("Colonne de regroupement non autorisée : {$groupColumn}");
        }

        $rows = $query->select($groupColumn)
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy($groupColumn)
            ->get();

        return $rows->mapWithKeys(function ($row) use ($groupColumn): array {
            /** @var int|string $rawKey */
            $rawKey = $row->getAttribute($groupColumn);
            /** @var int|string $rawCount */
            $rawCount = $row->getAttribute('cnt');

            return [(int) $rawKey => (int) $rawCount];
        });
    }

    /**
     * @param  array<string, mixed>  $matiere
     * @param  array<int, array<string, mixed>>  $evaluations
     * @param  array{published: Collection<int, int>, draft: Collection<int, int>, seances: Collection<int, int>}  $stats
     * @return array<string, mixed>
     */
    private function enrichMatiere(array $matiere, array $evaluations, array $stats): array
    {
        $matiereId = KlassciPayload::toInt($matiere['id'] ?? $matiere['matiere_id'] ?? null);

        if ($matiereId === null) {
            return $matiere;
        }

        $nombreEvaluations = collect($evaluations)->filter(function (array $evaluation) use ($matiereId): bool {
            $matiereData = $evaluation['matiere'] ?? null;
            $evalMatiereId = is_array($matiereData)
                ? ($matiereData['id'] ?? $matiereData['matiere_id'] ?? null)
                : ($evaluation['matiere_id'] ?? null);

            return $evalMatiereId == $matiereId;
        })->count();

        $matiere['statistiques'] = [
            'nombre_lessons_publiees' => (int) ($stats['published'][$matiereId] ?? 0),
            'nombre_lessons_brouillons' => (int) ($stats['draft'][$matiereId] ?? 0),
            'nombre_seances' => (int) ($stats['seances'][$matiereId] ?? 0),
            'nombre_evaluations' => $nombreEvaluations,
        ];

        return $matiere;
    }
}
