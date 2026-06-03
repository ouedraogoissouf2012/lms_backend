<?php

declare(strict_types=1);

namespace App\Services\Institution;

use App\Models\Institution;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * CRUD des institutions (admin/supradmin only).
 *
 * Extrait de `InstitutionController` (split `split-12/institution`,
 * §5 controllers ≤200 l + §1.1 services ≤300 l + §1.6 D strict DI).
 *
 * ## Responsabilités
 *
 *   - Création / mise à jour / soft delete d'une institution.
 *   - Toggle is_active avec garde-fou « ne jamais désactiver la dernière active ».
 *   - Invalidation des caches list/overview après chaque écriture.
 *
 * La validation des payloads reste dans le controller (FormRequest implicite),
 * ce service reçoit un tableau déjà validé.
 *
 * @see app/Services/Institution/InstitutionQueryService.php  Lecture + clés cache
 */
final class InstitutionCrudService
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(array $validated): Institution
    {
        /** @var Institution $institution */
        $institution = Institution::create($validated);

        $this->invalidateCaches();

        $this->logger->info('Institution created', [
            'id' => $institution->id,
            'slug' => $institution->slug,
        ]);

        return $institution;
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function update(int $id, array $validated): Institution
    {
        /** @var Institution $institution */
        $institution = Institution::findOrFail($id);
        $institution->update($validated);

        $this->invalidateCaches();

        $this->logger->info('Institution updated', [
            'id' => $id,
            'fields' => array_keys($validated),
        ]);

        /** @var Institution $fresh */
        $fresh = $institution->fresh();

        return $fresh;
    }

    /**
     * Inverse le flag is_active. Refuse si on désactiverait la dernière active.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \RuntimeException  Si tentative de désactiver la dernière institution active.
     */
    public function toggleActive(int $id): Institution
    {
        /** @var Institution $institution */
        $institution = Institution::findOrFail($id);

        if ($institution->is_active) {
            $activeCount = Institution::where('is_active', true)->count();
            if ($activeCount <= 1) {
                throw new RuntimeException(
                    'Impossible de désactiver la dernière institution active'
                );
            }
        }

        $institution->update(['is_active' => !$institution->is_active]);

        $this->invalidateCaches();

        $this->logger->info('Institution toggled', [
            'id' => $id,
            'is_active' => $institution->is_active,
        ]);

        /** @var Institution $fresh */
        $fresh = $institution->fresh();

        return $fresh;
    }

    /**
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function softDelete(int $id): void
    {
        /** @var Institution $institution */
        $institution = Institution::findOrFail($id);
        $institution->delete();

        $this->invalidateCaches();

        $this->logger->info('Institution deleted', ['id' => $id]);
    }

    private function invalidateCaches(): void
    {
        $this->cache->forget(InstitutionQueryService::CACHE_KEY_LIST);
        $this->cache->forget(InstitutionQueryService::CACHE_KEY_OVERVIEW);
    }
}
