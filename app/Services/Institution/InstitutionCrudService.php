<?php

declare(strict_types=1);

namespace App\Services\Institution;

use App\Exceptions\BusinessException;
use App\Models\Institution;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

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
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $db,
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
     * @throws \App\Exceptions\BusinessException  Si tentative de désactiver la dernière institution active.
     */
    public function toggleActive(int $id): Institution
    {
        /** @var Institution $institution */
        $institution = Institution::findOrFail($id);

        if ($institution->is_active) {
            $activeCount = Institution::where('is_active', true)->count();
            if ($activeCount <= 1) {
                throw new \App\Exceptions\BusinessException(
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
     * Suppression LOGIQUE d'une institution (#567).
     *
     * Une suppression n'est jamais un premier geste : l'institution doit être
     * déjà désactivée (`is_active = false`). Refuser une institution active
     * empêche a fortiori de supprimer la dernière active — mais UNIQUEMENT si
     * toute désactivation transite par {@see toggleActive()}, qui tient le
     * compteur « ≥ 1 active ». Un `update(['is_active' => false])` direct peut
     * encore contourner ce compteur : limite PRÉ-EXISTANTE (invariant à
     * centraliser), hors périmètre #567.
     *
     * Coupe l'accès des utilisateurs du tenant (révocation Sanctum) : sans #565,
     * une institution soft-deletée résoudrait un tenant `null` (fail-open) —
     * révoquer les sessions ferme cette fenêtre indépendamment de #565.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws BusinessException  Si l'institution est encore active.
     */
    public function softDelete(int $id): void
    {
        /** @var Institution $institution */
        $institution = Institution::findOrFail($id);

        if ($institution->is_active) {
            throw new BusinessException(
                'Désactivez l\'institution avant de la supprimer.'
            );
        }

        // Atomicité : révocation des sessions ↔ soft delete dans une transaction —
        // jamais « sessions coupées mais institution encore là » (ou l'inverse).
        $revokedSessions = $this->db->transaction(function () use ($institution, $id): int {
            $revoked = $this->revokeTenantSessions($id);
            $institution->delete();

            $this->audit->logSecurityEvent('institution.soft_deleted', $institution, [
                'revoked_sessions' => $revoked,
            ]);

            return $revoked;
        });

        $this->invalidateCaches();

        $this->logger->info('Institution soft-deleted', [
            'id' => $id,
            'revoked_sessions' => $revokedSessions,
        ]);
    }

    /**
     * Révoque tous les jetons Sanctum des utilisateurs du tenant (sous-requête
     * bornée — aucun chargement d'ids en mémoire). Retourne le nombre révoqué.
     */
    private function revokeTenantSessions(int $institutionId): int
    {
        // delete() est typé `mixed` par Larastan bien qu'il renvoie le nombre de
        // lignes supprimées → narrowing is_int (pas de cast, niveau 9).
        $deleted = PersonalAccessToken::query()
            ->where('tokenable_type', (new User())->getMorphClass())
            ->whereIn(
                'tokenable_id',
                User::withoutGlobalScope('institution')
                    ->where('institution_id', $institutionId)
                    ->select('id')
            )
            ->delete();

        return is_int($deleted) ? $deleted : 0;
    }

    private function invalidateCaches(): void
    {
        $this->cache->forget(InstitutionQueryService::CACHE_KEY_LIST);
        $this->cache->forget(InstitutionQueryService::CACHE_KEY_OVERVIEW);
    }
}
