<?php

declare(strict_types=1);

namespace App\Services\Institution;

use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;

/**
 * Lecture cross-tenant des institutions (admin/supradmin only).
 *
 * Extrait de `InstitutionController` (split `split-12/institution`,
 * §5 controllers ≤200 l + §1.1 services ≤300 l + §1.6 D strict DI).
 *
 * ## SECURITY — cross-tenant
 *
 * Toutes les queries de ce service utilisent `withoutGlobalScope('institution')`
 * pour agréger des statistiques sur l'ensemble du parc. Ce bypass est sûr
 * UNIQUEMENT parce que les endpoints qui appellent ce service sont protégés
 * par `role:supradmin` côté routes (cf. `routes/api.php` groupe
 * `admin/institutions`). Ne JAMAIS appeler ce service depuis un endpoint
 * non-supradmin.
 *
 * @see app/Http/Controllers/API/InstitutionController.php
 * @see app/Models/Traits/BelongsToInstitution.php
 */
final class InstitutionQueryService
{
    /** Durée cache (secondes) pour la liste globale et l'overview. */
    private const CACHE_TTL = 120;

    public const CACHE_KEY_LIST = 'global_institutions_list';

    public const CACHE_KEY_OVERVIEW = 'global_institutions_overview';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {
    }

    /**
     * Liste de toutes les institutions avec stats par institution.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listAllWithStats(): Collection
    {
        /** @var Collection<int, array<string, mixed>> $result */
        $result = $this->cache->remember(self::CACHE_KEY_LIST, self::CACHE_TTL, function (): Collection {
            return Institution::orderBy('name')->get()->map(
                fn (Institution $institution): array => $this->buildListItem($institution)
            );
        });

        return $result;
    }

    /**
     * Stats globales (totaux cross-tenant) — invalidé en même temps que la liste.
     *
     * @return array<string, int>
     */
    public function getGlobalOverview(): array
    {
        /** @var array<string, int> $result */
        $result = $this->cache->remember(self::CACHE_KEY_OVERVIEW, self::CACHE_TTL, function (): array {
            $total = Institution::count();
            $active = Institution::where('is_active', true)->count();

            return [
                'total_institutions' => $total,
                'active_institutions' => $active,
                'inactive_institutions' => $total - $active,
                'total_users' => User::withoutGlobalScope('institution')->count(),
                'total_lessons' => Lesson::withoutGlobalScope('institution')->count(),
                'total_evaluations' => Evaluation::withoutGlobalScope('institution')->count(),
            ];
        });

        return $result;
    }

    /**
     * Détails d'une institution + stats détaillées.
     *
     * @return array{institution: Institution, stats: array<string, mixed>}|null
     */
    public function getOneWithStats(int $id): ?array
    {
        /** @var Institution|null $institution */
        $institution = Institution::find($id);
        if ($institution === null) {
            return null;
        }

        return [
            'institution' => $institution,
            'stats' => $this->buildDetailedStats($id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildListItem(Institution $institution): array
    {
        $id = $institution->id;

        return [
            'id' => $institution->id,
            'slug' => $institution->slug,
            'name' => $institution->name,
            'logo_url' => $institution->logo_url,
            'primary_color' => $institution->primary_color,
            'is_active' => $institution->is_active,
            'klassci_api_url' => $institution->klassci_api_url,
            'created_at' => $institution->created_at,
            'updated_at' => $institution->updated_at,
            'stats' => [
                'users_count' => User::withoutGlobalScope('institution')->where('institution_id', $id)->count(),
                'lessons_count' => Lesson::withoutGlobalScope('institution')->where('institution_id', $id)->count(),
                'evaluations_count' => Evaluation::withoutGlobalScope('institution')->where('institution_id', $id)->count(),
                'classes_count' => Classe::withoutGlobalScope('institution')->where('institution_id', $id)->count(),
                'last_activity' => User::withoutGlobalScope('institution')->where('institution_id', $id)->max('updated_at'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDetailedStats(int $id): array
    {
        $usersByRole = User::withoutGlobalScope('institution')
            ->where('institution_id', $id)
            ->selectRaw('role, COUNT(*) as count')
            ->groupBy('role')
            ->pluck('count', 'role');

        return [
            'users_count' => User::withoutGlobalScope('institution')->where('institution_id', $id)->count(),
            'users_by_role' => $usersByRole,
            'lessons_count' => Lesson::withoutGlobalScope('institution')->where('institution_id', $id)->count(),
            'published_lessons' => Lesson::withoutGlobalScope('institution')
                ->where('institution_id', $id)
                ->where('status', 'published')->count(),
            'evaluations_count' => Evaluation::withoutGlobalScope('institution')->where('institution_id', $id)->count(),
            'classes_count' => Classe::withoutGlobalScope('institution')->where('institution_id', $id)->count(),
        ];
    }
}
