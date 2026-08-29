<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Lecture des notifications utilisateur + stats admin.
 *
 * Extrait de `NotificationsController` (split `split-21/notifications`,
 * §5 controllers ≤200 l + §1.1 services ≤300 l + §1.6 D strict DI).
 *
 * ## Responsabilités
 *
 *   - Pagination de l'inbox d'un utilisateur ({@see paginate()}).
 *   - Compteur non-lu pour le badge ({@see unreadCount()}).
 *   - Liste des 5 dernières non-lues pour le widget dashboard
 *     ({@see recent()}) — projection front via {@see NotificationPresenter}.
 *   - Stats globales / scoped par institution pour `/admin/notifications/stats`
 *     ({@see stats()}) — préserve l'isolation tenant (issue #98).
 *
 * ## Cache
 *
 * Tous les reads passent par {@see CacheRepository::remember()} avec une clé
 * **strictement préfixée par `notifications_*`** pour que
 * {@see NotificationMutationService::invalidate()} (cache.forget par clé) reste
 * cohérent avec les anciens patterns du controller.
 *
 * Les clés stats sont namespacées par institution (`_inst_{id}` ou
 * `_global`) pour empêcher toute fuite cross-tenant via `Cache::remember`.
 *
 * ## DI strict (§1.6 D)
 *
 *   - `CacheRepository` (jamais `Cache::` Facade).
 *   - `ConnectionInterface` pour les requêtes `stats` qui doivent bypass le
 *     scope global `BelongsToInstitution` (supradmin = cross-tenant).
 *   - `NotificationPresenter` pour les `action_url` du widget récent.
 *
 * @see app/Services/Notification/NotificationMutationService.php  Écritures
 * @see app/Services/Notification/NotificationPresenter.php        Mapping front
 */
final class NotificationQueryService
{
    /**
     * TTL court (60 s) pour l'inbox / unread-count / recent : compromis entre
     * fraîcheur perçue côté UI et coût des reads sur la table notifications.
     */
    private const CACHE_TTL_LIST = 60;

    /**
     * TTL plus long (5 min) pour les stats admin : agrégats coûteux,
     * l'utilisateur attend une vue dashboard, pas du temps-réel.
     */
    private const CACHE_TTL_STATS = 300;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConnectionInterface $db,
        private readonly NotificationPresenter $presenter,
    ) {
    }

    /**
     * Paginer les notifications d'un utilisateur. `$unreadOnly` filtre sur
     * `read_at IS NULL`. `$page` est passé explicitement pour qu'il soit
     * inclus dans la clé de cache (sinon deux pages distinctes partageraient
     * la même entrée).
     *
     * @return LengthAwarePaginator<int, Notification>
     */
    public function paginate(User $user, int $perPage, bool $unreadOnly, int $page): LengthAwarePaginator
    {
        $cacheKey = "notifications_user_{$user->id}_unread_" . ($unreadOnly ? '1' : '0') . "_page_{$page}";

        /** @var LengthAwarePaginator<int, Notification> */
        return $this->cache->remember($cacheKey, self::CACHE_TTL_LIST, function () use ($user, $perPage, $unreadOnly) {
            $query = Notification::where('user_id', $user->id);

            if ($unreadOnly) {
                $query->whereNull('read_at');
            }

            return $query->orderBy('created_at', 'desc')->paginate($perPage);
        });
    }

    /**
     * Compteur de notifications non lues — clé de cache stable par user pour
     * que {@see NotificationMutationService} puisse l'invalider à chaque
     * mark/delete.
     */
    public function unreadCount(User $user): int
    {
        $cacheKey = "notifications_unread_count_user_{$user->id}";

        return (int) $this->cache->remember($cacheKey, self::CACHE_TTL_LIST, function () use ($user): int {
            return Notification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->count();
        });
    }

    /**
     * Les `$limit` dernières notifications **non lues** pour le widget
     * dashboard. Projection enrichie (action_url, icon, color, time_ago)
     * pour éviter un round-trip frontend.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function recent(User $user, int $limit): Collection
    {
        $cacheKey = "notifications_recent_user_{$user->id}_limit_{$limit}";

        /** @var Collection<int, array<string, mixed>> */
        return $this->cache->remember($cacheKey, self::CACHE_TTL_LIST, function () use ($user, $limit): Collection {
            return Notification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function (Notification $notification): array {
                    // `created_at` est `?Carbon` côté Eloquent (timestamp non
                    // peuplé avant persistance) — on lit de la DB après
                    // `whereNull('read_at')`, donc une ligne existe et
                    // `created_at` est garanti non-null. On capture une
                    // référence locale pour que PHPStan ne perde pas le
                    // narrowing entre les deux appels.
                    $createdAt = $notification->created_at;

                    return [
                        'id' => $notification->id,
                        'type' => $notification->type,
                        'title' => $notification->title,
                        'message' => $notification->message,
                        'data' => $notification->data,
                        'read_at' => $notification->read_at,
                        'created_at' => $createdAt?->toISOString(),
                        'time_ago' => $createdAt?->diffForHumans(),
                        'is_unread' => is_null($notification->read_at),
                        'action_url' => $this->presenter->getActionUrl($notification),
                        'icon' => $this->presenter->icon($notification),
                        'color' => $this->presenter->color($notification),
                    ];
                });
        });
    }

    /**
     * Statistiques admin de notifications (total, unread, last_24h, last_7days,
     * by_type).
     *
     * Tenant isolation (issue #98) :
     *   - `supradmin` (platform manager) — counts cross-tenant via global query.
     *   - Tous les autres rôles autorisés — counts scoped à `institution_id`.
     *
     * SECURITY : on utilise `DB::table('notifications')` plutôt qu'Eloquent
     * pour bypass le scope global `BelongsToInstitution`. Le filtre tenant
     * est appliqué manuellement via `$applyTenantFilter` — NE PAS supprimer
     * ce filtre sans repenser l'isolation, sinon un coordinateur d'institution
     * A verra les counts de B.
     *
     * @return array{
     *     total: int,
     *     unread: int,
     *     read: int,
     *     last_24h: int,
     *     last_7days: int,
     *     by_type: \Illuminate\Support\Collection<int, object>
     * }
     */
    public function stats(User $caller): array
    {
        // #497 : STRICTEMENT le gestionnaire plateforme (role === 'supradmin'),
        // PAS asRoleEnum() qui inclurait aussi 'superAdmin' (admin d'institution)
        // → fuite cross-tenant. cf. isPlatformSupradmin().
        $isSupradmin = $caller->isPlatformSupradmin();

        $cacheKey = $isSupradmin
            ? 'notifications_stats_global'
            : "notifications_stats_inst_{$caller->institution_id}";

        /** @var array{
         *     total: int,
         *     unread: int,
         *     read: int,
         *     last_24h: int,
         *     last_7days: int,
         *     by_type: \Illuminate\Support\Collection<int, object>
         * } */
        return $this->cache->remember($cacheKey, self::CACHE_TTL_STATS, function () use ($isSupradmin, $caller): array {
            $applyTenantFilter = static function (QueryBuilder $query) use ($isSupradmin, $caller): QueryBuilder {
                return $isSupradmin
                    ? $query
                    : $query->where('institution_id', $caller->institution_id);
            };

            $totalNotifications = $applyTenantFilter($this->db->table('notifications'))->count();

            $unreadNotifications = $applyTenantFilter($this->db->table('notifications'))
                ->whereNull('read_at')
                ->count();

            $last24h = $applyTenantFilter($this->db->table('notifications'))
                ->where('created_at', '>=', Carbon::now()->subDay())
                ->count();

            $last7days = $applyTenantFilter($this->db->table('notifications'))
                ->where('created_at', '>=', Carbon::now()->subDays(7))
                ->count();

            $byType = $applyTenantFilter($this->db->table('notifications'))
                ->select('type', $this->db->raw('count(*) as count'))
                ->groupBy('type')
                ->get();

            return [
                'total' => $totalNotifications,
                'unread' => $unreadNotifications,
                'read' => $totalNotifications - $unreadNotifications,
                'last_24h' => $last24h,
                'last_7days' => $last7days,
                'by_type' => $byType,
            ];
        });
    }
}
