<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\MarkAsReadRequest;
use App\Http\Requests\MarkAllAsReadRequest;
use App\Http\Requests\DeleteNotificationRequest;
use App\Http\Requests\DeleteAllReadNotificationsRequest;
use App\Http\Requests\CreateNotificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class NotificationsController extends AuthenticatedController
{
    /**
     * Récupérer toutes les notifications de l'utilisateur connecté
     */
    public function index(Request $request)
    {
        $user = $this->authenticatedUser($request);
        $perPage = $request->input('per_page', 10);
        $unreadOnly = $request->input('unread_only', false);

        $cacheKey = "notifications_user_{$user->id}_unread_{$unreadOnly}_page_" . $request->input('page', 1);
        $cacheTTL = 60; // 1 minute

        $notifications = Cache::remember($cacheKey, $cacheTTL, function () use ($user, $perPage, $unreadOnly) {
            $query = \App\Models\Notification::where('user_id', $user->id);

            if ($unreadOnly) {
                $query->whereNull('read_at');
            }

            return $query->orderBy('created_at', 'desc')->paginate($perPage);
        });

        return response()->json([
            'success' => true,
            'data' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ]
        ]);
    }

    /**
     * Récupérer les notifications non lues (pour le badge)
     */
    public function unreadCount(Request $request)
    {
        $user = $this->authenticatedUser($request);
        $cacheKey = "notifications_unread_count_user_{$user->id}";
        $cacheTTL = 60; // 1 minute

        $count = Cache::remember($cacheKey, $cacheTTL, function () use ($user) {
            return \App\Models\Notification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->count();
        });

        return response()->json([
            'success' => true,
            'count' => $count
        ]);
    }

    /**
     * Récupérer les dernières notifications (widget dashboard)
     */
    public function recent(Request $request)
    {
        $user = $this->authenticatedUser($request);
        $limit = $request->input('limit', 5);

        $cacheKey = "notifications_recent_user_{$user->id}_limit_{$limit}";
        $cacheTTL = 60; // 1 minute

        $notifications = Cache::remember($cacheKey, $cacheTTL, function () use ($user, $limit) {
            return \App\Models\Notification::where('user_id', $user->id)
                ->whereNull('read_at') // Seulement les notifications non lues
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($notification) {
                    return [
                        'id' => $notification->id,
                        'type' => $notification->type,
                        'title' => $notification->title,
                        'message' => $notification->message,
                        'data' => $notification->data,
                        'read_at' => $notification->read_at,
                        'created_at' => $notification->created_at->toISOString(),
                        'time_ago' => $notification->created_at->diffForHumans(),
                        'is_unread' => is_null($notification->read_at),
                        'action_url' => $notification->getActionUrl(),
                        'icon' => $notification->getIcon(),
                        'color' => $notification->getColor(),
                    ];
                });
        });

        return response()->json([
            'success' => true,
            'data' => $notifications
        ]);
    }

    /**
     * Marquer une notification comme lue
     */
    public function markAsRead($id, MarkAsReadRequest $request)
    {
        $user = $this->authenticatedUser($request);
        $notification = \App\Models\Notification::where('user_id', $user->id)->findOrFail($id);

        if (!$notification->read_at) {
            $notification->markAsRead();

            // Cache invalidation for user's notification counts only
            Cache::forget("notifications_unread_count_user_{$user->id}");
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification marquée comme lue'
        ]);
    }

    /**
     * Marquer toutes les notifications comme lues
     */
    public function markAllAsRead(MarkAllAsReadRequest $request)
    {
        $user = $this->authenticatedUser($request);

        \App\Models\Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        // Cache invalidation for user's notification counts only
        Cache::forget("notifications_unread_count_user_{$user->id}");

        return response()->json([
            'success' => true,
            'message' => 'Toutes les notifications ont été marquées comme lues'
        ]);
    }

    /**
     * Supprimer une notification
     */
    public function delete($id, DeleteNotificationRequest $request)
    {
        $user = $this->authenticatedUser($request);
        $notification = \App\Models\Notification::where('user_id', $user->id)->findOrFail($id);

        $notification->delete();

        // Cache invalidation for user's notification counts only
        Cache::forget("notifications_unread_count_user_{$user->id}");

        return response()->json([
            'success' => true,
            'message' => 'Notification supprimée'
        ]);
    }

    /**
     * Supprimer toutes les notifications lues
     */
    public function deleteAllRead(DeleteAllReadNotificationsRequest $request)
    {
        $user = $this->authenticatedUser($request);

        \App\Models\Notification::where('user_id', $user->id)
            ->whereNotNull('read_at')
            ->delete();

        // Cache invalidation for user's notification counts only
        Cache::forget("notifications_unread_count_user_{$user->id}");

        return response()->json([
            'success' => true,
            'message' => 'Toutes les notifications lues ont été supprimées'
        ]);
    }

    /**
     * Créer une notification manuelle (admin only)
     */
    public function create(CreateNotificationRequest $request)
    {
        $user = \App\Models\User::findOrFail($request->user_id);

        // Créer notification directement dans la base (notre schéma personnalisé)
        \App\Models\Notification::create([
            'user_id' => $user->id,
            'institution_id' => $user->institution_id,
            'type' => $request->type ?? 'info',
            'title' => $request->title,
            'message' => $request->message,
            'data' => ['action_url' => $request->action_url],
            'read_at' => null,
        ]);

        // Invalider le cache
        Cache::forget("notifications_unread_count_user_{$user->id}");

        return response()->json([
            'success' => true,
            'message' => 'Notification envoyée'
        ]);
    }

    /**
     * Récupérer les statistiques de notifications (admin).
     *
     * Tenant isolation (issue #98 fix):
     *   - Non-`supradmin` callers see counts scoped to their own institution.
     *   - `supradmin` (platform manager) sees global counts cross-tenant.
     *
     * Cache key is namespaced per institution to prevent cross-tenant leak via
     * Cache::remember. Each institution gets its own cache slot.
     */
    public function stats(Request $request)
    {
        $caller = $this->authenticatedUser($request);
        $isSupradmin = $caller->role === 'supradmin';

        $cacheKey = $isSupradmin
            ? 'notifications_stats_global'
            : "notifications_stats_inst_{$caller->institution_id}";
        $cacheTTL = 300; // 5 minutes

        $stats = Cache::remember($cacheKey, $cacheTTL, function () use ($isSupradmin, $caller) {
            $applyTenantFilter = function ($query) use ($isSupradmin, $caller) {
                return $isSupradmin
                    ? $query
                    : $query->where('institution_id', $caller->institution_id);
            };

            $totalNotifications = $applyTenantFilter(DB::table('notifications'))->count();
            $unreadNotifications = $applyTenantFilter(DB::table('notifications'))
                ->whereNull('read_at')
                ->count();

            $last24h = $applyTenantFilter(DB::table('notifications'))
                ->where('created_at', '>=', Carbon::now()->subDay())
                ->count();

            $last7days = $applyTenantFilter(DB::table('notifications'))
                ->where('created_at', '>=', Carbon::now()->subDays(7))
                ->count();

            $byType = $applyTenantFilter(DB::table('notifications'))
                ->select('type', DB::raw('count(*) as count'))
                ->groupBy('type')
                ->get();

            return [
                'total' => $totalNotifications,
                'unread' => $unreadNotifications,
                'read' => $totalNotifications - $unreadNotifications,
                'last_24h' => $last24h,
                'last_7days' => $last7days,
                'by_type' => $byType
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}
