<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class NotificationsController extends Controller
{
    /**
     * Récupérer toutes les notifications de l'utilisateur connecté
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $perPage = $request->input('per_page', 10);
        $unreadOnly = $request->input('unread_only', false);

        $cacheKey = "notifications_user_{$user->id}_unread_{$unreadOnly}_page_" . $request->input('page', 1);
        $cacheTTL = 60; // 1 minute

        $notifications = Cache::remember($cacheKey, $cacheTTL, function () use ($user, $perPage, $unreadOnly) {
            $query = $user->notifications();

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
    public function unreadCount()
    {
        $user = Auth::user();
        $cacheKey = "notifications_unread_count_user_{$user->id}";
        $cacheTTL = 60; // 1 minute

        $count = Cache::remember($cacheKey, $cacheTTL, function () use ($user) {
            return $user->unreadNotifications()->count();
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
        $user = Auth::user();
        $limit = $request->input('limit', 5);

        $cacheKey = "notifications_recent_user_{$user->id}_limit_{$limit}";
        $cacheTTL = 60; // 1 minute

        $notifications = Cache::remember($cacheKey, $cacheTTL, function () use ($user, $limit) {
            return $user->notifications()
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
    public function markAsRead($id)
    {
        $user = Auth::user();
        $notification = $user->notifications()->findOrFail($id);

        if (!$notification->read_at) {
            $notification->markAsRead();

            // Invalider le cache
            Cache::forget("notifications_unread_count_user_{$user->id}");
            Cache::flush(); // Flush all notification caches for this user
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification marquée comme lue'
        ]);
    }

    /**
     * Marquer toutes les notifications comme lues
     */
    public function markAllAsRead()
    {
        $user = Auth::user();

        $user->unreadNotifications->markAsRead();

        // Invalider le cache
        Cache::forget("notifications_unread_count_user_{$user->id}");
        Cache::flush(); // Flush all notification caches

        return response()->json([
            'success' => true,
            'message' => 'Toutes les notifications ont été marquées comme lues'
        ]);
    }

    /**
     * Supprimer une notification
     */
    public function delete($id)
    {
        $user = Auth::user();
        $notification = $user->notifications()->findOrFail($id);

        $notification->delete();

        // Invalider le cache
        Cache::forget("notifications_unread_count_user_{$user->id}");
        Cache::flush();

        return response()->json([
            'success' => true,
            'message' => 'Notification supprimée'
        ]);
    }

    /**
     * Supprimer toutes les notifications lues
     */
    public function deleteAllRead()
    {
        $user = Auth::user();

        $user->readNotifications()->delete();

        // Invalider le cache
        Cache::flush();

        return response()->json([
            'success' => true,
            'message' => 'Toutes les notifications lues ont été supprimées'
        ]);
    }

    /**
     * Créer une notification manuelle (admin only)
     */
    public function create(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'nullable|string|in:info,success,warning,danger',
            'action_url' => 'nullable|url'
        ]);

        $user = \App\Models\User::findOrFail($request->user_id);

        // Créer notification
        $user->notify(new \App\Notifications\CustomNotification(
            $request->title,
            $request->message,
            $request->type ?? 'info',
            $request->action_url
        ));

        // Invalider le cache
        Cache::forget("notifications_unread_count_user_{$user->id}");

        return response()->json([
            'success' => true,
            'message' => 'Notification envoyée'
        ]);
    }

    /**
     * Récupérer les statistiques de notifications (admin)
     */
    public function stats()
    {
        $cacheKey = 'notifications_stats_admin';
        $cacheTTL = 300; // 5 minutes

        $stats = Cache::remember($cacheKey, $cacheTTL, function () {
            $totalNotifications = DB::table('notifications')->count();
            $unreadNotifications = DB::table('notifications')
                ->whereNull('read_at')
                ->count();

            $last24h = DB::table('notifications')
                ->where('created_at', '>=', Carbon::now()->subDay())
                ->count();

            $last7days = DB::table('notifications')
                ->where('created_at', '>=', Carbon::now()->subDays(7))
                ->count();

            $byType = DB::table('notifications')
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
