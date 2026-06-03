<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\CreateNotificationRequest;
use App\Http\Requests\DeleteAllReadNotificationsRequest;
use App\Http\Requests\DeleteNotificationRequest;
use App\Http\Requests\MarkAllAsReadRequest;
use App\Http\Requests\MarkAsReadRequest;
use App\Models\User;
use App\Services\Notification\NotificationMutationService;
use App\Services\Notification\NotificationQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints REST pour les notifications utilisateur + admin.
 *
 * Thin controller (split `split-21/notifications`) : la logique métier est
 * déléguée aux services SRP pour respecter §5 (controllers ≤200 l).
 *
 *   - Lectures (index, unreadCount, recent, stats) → {@see NotificationQueryService}
 *   - Écritures (markAsRead, markAllAsRead, delete, deleteAllRead, create)
 *     → {@see NotificationMutationService}
 *
 * Le contrôleur reste responsable de :
 *   - Authentification (via {@see AuthenticatedController::authenticatedUser()}).
 *   - Validation (via FormRequest).
 *   - Sérialisation JSON (forme de réponse stable côté front).
 *
 * Routes définies dans `routes/api.php` (préfixes `/notifications` et
 * `/admin/notifications`).
 *
 * @see app/Services/Notification/NotificationQueryService.php
 * @see app/Services/Notification/NotificationMutationService.php
 */
final class NotificationsController extends AuthenticatedController
{
    public function __construct(
        private readonly NotificationQueryService $queryService,
        private readonly NotificationMutationService $mutationService,
    ) {
    }

    /**
     * GET /notifications — Liste paginée des notifications de l'utilisateur.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        // Request::integer() / boolean() retournent un type strict — pas un
        // cast pour silence PHPStan mais l'API publique de Laravel pour
        // typer un input HTTP (mixed) en int/bool de manière sûre.
        $perPage = $request->integer('per_page', 10);
        $unreadOnly = $request->boolean('unread_only', false);
        $page = $request->integer('page', 1);

        $notifications = $this->queryService->paginate($user, $perPage, $unreadOnly, $page);

        return response()->json([
            'success' => true,
            'data' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    /**
     * GET /notifications/unread-count — Compteur pour badge UI.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        return response()->json([
            'success' => true,
            'count' => $this->queryService->unreadCount($user),
        ]);
    }

    /**
     * GET /notifications/recent — 5 dernières non-lues pour le widget dashboard.
     */
    public function recent(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $limit = $request->integer('limit', 5);

        return response()->json([
            'success' => true,
            'data' => $this->queryService->recent($user, $limit),
        ]);
    }

    /**
     * POST /notifications/{id}/mark-as-read — Marquer une notification comme lue.
     */
    public function markAsRead(int|string $id, MarkAsReadRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $this->mutationService->markAsRead($user, $id);

        return response()->json([
            'success' => true,
            'message' => 'Notification marquée comme lue',
        ]);
    }

    /**
     * POST /notifications/mark-all-as-read — Marquer toutes comme lues.
     */
    public function markAllAsRead(MarkAllAsReadRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $this->mutationService->markAllAsRead($user);

        return response()->json([
            'success' => true,
            'message' => 'Toutes les notifications ont été marquées comme lues',
        ]);
    }

    /**
     * DELETE /notifications/{id} — Supprimer une notification.
     */
    public function delete(int|string $id, DeleteNotificationRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $this->mutationService->delete($user, $id);

        return response()->json([
            'success' => true,
            'message' => 'Notification supprimée',
        ]);
    }

    /**
     * DELETE /notifications/read/all — Supprimer toutes les notifications lues.
     */
    public function deleteAllRead(DeleteAllReadNotificationsRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $this->mutationService->deleteAllRead($user);

        return response()->json([
            'success' => true,
            'message' => 'Toutes les notifications lues ont été supprimées',
        ]);
    }

    /**
     * POST /admin/notifications/create — Création manuelle (admin only).
     */
    public function create(CreateNotificationRequest $request): JsonResponse
    {
        /** @var User $recipient */
        $recipient = User::findOrFail($request->user_id);

        $this->mutationService->create($recipient, [
            'type' => $request->type ?? 'info',
            'title' => $request->title,
            'message' => $request->message,
            'action_url' => $request->action_url,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Notification envoyée',
        ]);
    }

    /**
     * GET /admin/notifications/stats — Stats globales (supradmin) ou scoped
     * (autres rôles admin). Voir {@see NotificationQueryService::stats()}.
     */
    public function stats(Request $request): JsonResponse
    {
        $caller = $this->authenticatedUser($request);

        return response()->json([
            'success' => true,
            'data' => $this->queryService->stats($caller),
        ]);
    }
}
