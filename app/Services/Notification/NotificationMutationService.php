<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Écritures sur les notifications utilisateur + création admin.
 *
 * Extrait de `NotificationsController` (split `split-21/notifications`,
 * §5 controllers ≤200 l + §1.1 services ≤300 l + §1.6 D strict DI).
 *
 * ## Responsabilités
 *
 *   - Marquer une notification comme lue ({@see markAsRead()}).
 *   - Marquer toutes les notifications d'un user comme lues
 *     ({@see markAllAsRead()}).
 *   - Soft delete d'une notification ({@see delete()}).
 *   - Suppression bulk des notifications déjà lues
 *     ({@see deleteAllRead()}).
 *   - Création manuelle d'une notification par un admin
 *     ({@see create()}).
 *
 * ## Invalidation cache
 *
 * Chaque écriture invalide la clé `notifications_unread_count_user_{id}` —
 * c'est la **seule clé partagée** avec {@see NotificationQueryService} qui
 * doit être strictement cohérente après une mutation (badge en haut à droite).
 * Les caches `recent` / `paginate` / `stats` sont laissés à leur TTL court
 * (60 s) pour éviter d'invalider trop large.
 *
 * ## DI strict (§1.6 D)
 *
 *   - `CacheRepository` injecté (jamais `Cache::` Facade).
 *   - `LoggerInterface` PSR-3 (jamais `Log::` Facade).
 *
 * @see app/Services/Notification/NotificationQueryService.php  Lectures
 */
final class NotificationMutationService
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Marquer une notification comme lue. Throw `ModelNotFoundException`
     * (→ 404) si la notification n'appartient pas au user — empêche un user
     * de marquer la notif d'un autre user comme lue.
     *
     * @throws ModelNotFoundException
     */
    public function markAsRead(User $user, int|string $id): void
    {
        /** @var Notification $notification */
        $notification = Notification::where('user_id', $user->id)->findOrFail($id);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        $this->invalidateUnreadCount($user);
    }

    /**
     * Marquer toutes les notifications non-lues d'un user comme lues.
     * Retourne le nombre d'enregistrements affectés (utile pour les tests
     * et un éventuel toast côté front).
     */
    public function markAllAsRead(User $user): int
    {
        $affected = Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $this->invalidateUnreadCount($user);

        return $affected;
    }

    /**
     * Soft delete d'une notification appartenant au user. Throw
     * `ModelNotFoundException` (→ 404) si la notification appartient à un
     * autre user — protection contre les IDOR via l'URL.
     *
     * @throws ModelNotFoundException
     */
    public function delete(User $user, int|string $id): void
    {
        /** @var Notification $notification */
        $notification = Notification::where('user_id', $user->id)->findOrFail($id);

        $notification->delete();

        $this->invalidateUnreadCount($user);
    }

    /**
     * Bulk delete de toutes les notifications déjà lues. Retourne le nombre
     * d'enregistrements supprimés.
     */
    public function deleteAllRead(User $user): int
    {
        // Eloquent `Builder::delete()` est PHPDoc-typé `mixed` côté Larastan
        // (le contrat sous-jacent renvoie `int`, mais l'inférence est perdue).
        // Plutôt que de caster un `mixed` (interdit par §0 — pas de cast pour
        // silence PHPStan), on garde le retour brut et on documente le contrat.
        $affected = Notification::where('user_id', $user->id)
            ->whereNotNull('read_at')
            ->delete();

        $this->invalidateUnreadCount($user);

        // Larastan : `Builder::delete()` est PHPDoc `int` à l'exécution mais
        // typé `mixed` ici à cause d'une infonarrowing manqué dans le stub
        // larastan/Builder. is_int() narrows sans cast.
        return is_int($affected) ? $affected : 0;
    }

    /**
     * Créer manuellement une notification (endpoint admin).
     *
     * Le `recipient` est résolu en amont par le controller (via
     * `User::findOrFail($request->user_id)`) pour que les FormRequest
     * `authorize()` puissent valider le scope tenant avant qu'on arrive ici.
     *
     * @param  array<string, mixed>  $payload
     *     Doit contenir : `type` (optionnel — défaut `'info'`), `title`,
     *     `message`, `action_url` (optionnel — wrappé dans `data`).
     */
    public function create(User $recipient, array $payload): Notification
    {
        // `institution_id` est passé explicitement pour forcer le scope du
        // destinataire — quand un `supradmin` crée une notification pour un
        // user en inst-B, on veut `institution_id = B`, pas le tenant courant
        // du caller. Le hook `creating` du trait `BelongsToInstitution`
        // détecte l'assignation explicite (`array_key_exists`) et la respecte.
        /** @var Notification $notification */
        $notification = Notification::query()->create([
            'user_id' => $recipient->id,
            'institution_id' => $recipient->institution_id,
            'type' => $payload['type'] ?? 'info',
            'title' => $payload['title'],
            'message' => $payload['message'],
            'data' => ['action_url' => $payload['action_url'] ?? null],
            'read_at' => null,
        ]);

        $this->invalidateUnreadCount($recipient);

        $this->logger->info('Notification created (admin endpoint)', [
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'type' => $notification->type,
        ]);

        return $notification;
    }

    /**
     * Invalide la clé de cache `unread_count` du user. Centralisée pour que
     * les 5 mutations restent strictement cohérentes : si on ajoute un autre
     * cache à invalider (ex : `recent`), on le fait ici une seule fois.
     */
    private function invalidateUnreadCount(User $user): void
    {
        $this->cache->forget("notifications_unread_count_user_{$user->id}");
    }
}
