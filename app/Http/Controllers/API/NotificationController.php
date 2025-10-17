<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * NotificationController
 *
 * Gestion des notifications utilisateur
 */
class NotificationController extends Controller
{
    /**
     * Liste des notifications de l'utilisateur connecté
     *
     * GET /api/notifications
     *
     * Query params:
     * - read: true/false (filtrer lues/non lues)
     * - type: string (filtrer par type)
     * - per_page: int (nombre par page, défaut 15)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Notification::query()
            ->forUser($request->user()->id)
            ->recent();

        // Filtre: Lues ou non lues
        if ($request->has('read')) {
            $isRead = filter_var($request->read, FILTER_VALIDATE_BOOLEAN);
            $query = $isRead ? $query->read() : $query->unread();
        }

        // Filtre: Par type
        if ($request->filled('type')) {
            $query->ofType($request->type);
        }

        $perPage = $request->input('per_page', 15);
        $notifications = $query->paginate($perPage);

        // Ajouter métadonnées
        $notifications->getCollection()->transform(function ($notification) {
            return array_merge($notification->toArray(), [
                'icon' => $notification->getIcon(),
                'color' => $notification->getColor(),
                'action_url' => $notification->getActionUrl(),
            ]);
        });

        return response()->json([
            'success' => true,
            'data' => $notifications,
            'unread_count' => Notification::forUser($request->user()->id)->unread()->count(),
        ]);
    }

    /**
     * Détails d'une notification
     *
     * GET /api/notifications/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $notification = Notification::query()
            ->forUser($request->user()->id)
            ->findOrFail($id);

        // Marquer comme lue automatiquement lors de la consultation
        if ($notification->isUnread()) {
            $notification->markAsRead();
        }

        return response()->json([
            'success' => true,
            'data' => array_merge($notification->toArray(), [
                'icon' => $notification->getIcon(),
                'color' => $notification->getColor(),
                'action_url' => $notification->getActionUrl(),
            ]),
        ]);
    }

    /**
     * Marquer une notification comme lue
     *
     * POST /api/notifications/{id}/read
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $notification = Notification::query()
            ->forUser($request->user()->id)
            ->findOrFail($id);

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notification marquée comme lue',
            'data' => $notification,
            'unread_count' => Notification::forUser($request->user()->id)->unread()->count(),
        ]);
    }

    /**
     * Marquer toutes les notifications comme lues
     *
     * POST /api/notifications/read-all
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $updated = Notification::query()
            ->forUser($request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => "$updated notifications marquées comme lues",
            'updated_count' => $updated,
        ]);
    }

    /**
     * Supprimer une notification
     *
     * DELETE /api/notifications/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $notification = Notification::query()
            ->forUser($request->user()->id)
            ->findOrFail($id);

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification supprimée',
        ]);
    }

    /**
     * Supprimer toutes les notifications lues
     *
     * DELETE /api/notifications/read
     */
    public function destroyAllRead(Request $request): JsonResponse
    {
        $deleted = Notification::query()
            ->forUser($request->user()->id)
            ->read()
            ->delete();

        return response()->json([
            'success' => true,
            'message' => "$deleted notifications supprimées",
            'deleted_count' => $deleted,
        ]);
    }

    /**
     * Obtenir le nombre de notifications non lues
     *
     * GET /api/notifications/unread-count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::query()
            ->forUser($request->user()->id)
            ->unread()
            ->count();

        return response()->json([
            'success' => true,
            'unread_count' => $count,
        ]);
    }

    /**
     * Marquer comme non lue
     *
     * POST /api/notifications/{id}/unread
     */
    public function markAsUnread(Request $request, int $id): JsonResponse
    {
        $notification = Notification::query()
            ->forUser($request->user()->id)
            ->findOrFail($id);

        $notification->markAsUnread();

        return response()->json([
            'success' => true,
            'message' => 'Notification marquée comme non lue',
            'data' => $notification,
            'unread_count' => Notification::forUser($request->user()->id)->unread()->count(),
        ]);
    }
}
