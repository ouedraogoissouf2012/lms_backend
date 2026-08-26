<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Classe;
use App\Models\ESBTPAttendance;
use App\Models\Evaluation;
use App\Models\Matiere;
use App\Models\Seance;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;

/**
 * #553 — statistiques admin fraîches, scopées tenant.
 *
 * Remplace le snapshot KLASSCI `admin_data.statistics` retiré du login (#504).
 * Calcul local Eloquent + BelongsToInstitution. Cache 300s par slug.
 */
final class AdminStatisticsService
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly TenantManager $tenantManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, int|float>
     */
    public function build(): array
    {
        $cacheKey = 'admin_statistics_'.$this->tenantManager->getResolvedSlug();
        $this->logger->debug('admin.statistics.request', ['cache_key' => $cacheKey]);

        return $this->cache->remember($cacheKey, self::CACHE_TTL_SECONDS, fn (): array => $this->aggregate());
    }

    /**
     * @return array<string, int|float>
     */
    private function aggregate(): array
    {
        $totalAttendances = ESBTPAttendance::query()->count();
        $presents = ESBTPAttendance::query()
            ->whereIn('status', ['connected', 'disconnected'])
            ->count();

        return [
            'nb_enseignants' => User::query()->whereIn('role', ['enseignant', 'teacher'])->count(),
            'nb_etudiants' => User::query()->whereIn('role', ['etudiant', 'student', 'étudiant'])->count(),
            'nb_classes_actives' => Classe::query()->count(),
            'nb_matieres_actives' => Matiere::query()->count(),
            'nb_filieres' => $this->distinctIds(Classe::class, Matiere::class, 'filiere_id'),
            'nb_niveaux' => $this->distinctIds(Classe::class, Matiere::class, 'niveau_id'),
            'nb_seances_actives' => Seance::query()->where('is_active', true)->count(),
            'nb_visios_actives' => Seance::query()->where('visio_active', true)->count(),
            'nb_evaluations' => Evaluation::query()->count(),
            'taux_presence' => $totalAttendances > 0
                ? round(($presents / $totalAttendances) * 100, 2)
                : 0.0,
        ];
    }

    /**
     * @param  class-string  $first
     * @param  class-string  $second
     */
    private function distinctIds(string $first, string $second, string $column): int
    {
        $ids = $first::query()->whereNotNull($column)->pluck($column)
            ->merge($second::query()->whereNotNull($column)->pluck($column))
            ->unique()
            ->filter();

        return $ids->count();
    }
}
