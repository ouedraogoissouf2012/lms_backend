<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Support\Collection;
use JsonException;

/**
 * Primitive bas niveau d'envoi de notifications + cleanup.
 *
 * Extrait de `NotificationService` (split notification-service, §1.1 ≤300 l).
 * Centralise les opérations CRUD bas niveau partagées par tous les dispatchers
 * métier (lesson, forum, quiz, visio).
 *
 * ## DI strict (§1.6 D)
 *
 * TenantManager injecté pour poser `institution_id` sur le insert() bulk
 * (les events Eloquent ne passent pas).
 *
 * @see app/Services/NotificationService.php  Facade orchestrateur
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly TenantManager $tenant,
    ) {
    }

    /**
     * Envoyer une notification à un utilisateur.
     *
     * @param  array<string, mixed>  $data
     */
    public function send(User $user, string $type, string $title, string $message, array $data = []): Notification
    {
        return Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * Envoyer une notification à plusieurs utilisateurs.
     *
     * @param  Collection<int, User>  $users
     * @param  array<string, mixed>  $data
     */
    public function sendToMany(Collection $users, string $type, string $title, string $message, array $data = []): int
    {
        if ($users->isEmpty()) {
            return 0;
        }

        $now = now();
        $fallbackInstitutionId = $this->tenant->id();
        try {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $encoded = '[]';
        }

        $rows = [];
        foreach ($users as $user) {
            $rows[] = [
                'user_id' => $user->id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => $encoded,
                'institution_id' => $user->institution_id ?? $fallbackInstitutionId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Notification::query()->insert($chunk);
        }

        return count($rows);
    }

    /**
     * Supprimer les anciennes notifications lues (cleanup).
     *
     * @param  int  $days  Nombre de jours à conserver (défaut: 30)
     */
    public function cleanupOldNotifications(int $days = 30): int
    {
        return (int) Notification::query()
            ->where('created_at', '<', now()->subDays($days))
            ->whereNotNull('read_at')
            ->delete();
    }
}
