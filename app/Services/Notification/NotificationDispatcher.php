<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Primitive bas niveau d'envoi de notifications + cleanup.
 *
 * Extrait de `NotificationService` (split notification-service, §1.1 ≤300 l).
 * Centralise les opérations CRUD bas niveau partagées par tous les dispatchers
 * métier (lesson, forum, quiz, visio).
 *
 * ## DI strict (§1.6 D)
 *
 * Aucune Facade — pas de dépendance constructeur car ce service ne logge pas
 * et n'a besoin que d'Eloquent. Pure mapping vers le model `Notification`.
 *
 * @see app/Services/NotificationService.php  Facade orchestrateur
 */
final class NotificationDispatcher
{
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
            // #579 — l'institution vient du DESTINATAIRE, pas du tenant ambiant.
            //
            // Émise depuis un worker ou un cron, la notification n'avait aucun
            // tenant résolu : le hook `creating` de `BelongsToInstitution`
            // journalisait et laissait la colonne à NULL. À la lecture en
            // revanche, une requête HTTP porteuse d'un token résout bien le
            // tenant et le scope global ajoute `WHERE institution_id = X` : la
            // ligne était écrite, et son destinataire ne la voyait jamais.
            //
            // Le poser ici rend la propriété STRUCTURELLE : il n'existe plus de
            // chemin par lequel une notification puisse naître sans tenant, là
            // où « restaurer le TenantManager dans chaque émetteur » impose de
            // ne jamais oublier — et l'oubli est silencieux.
            //
            // Un destinataire sans institution (supradmin) garde NULL : le hook
            // respecte toute valeur explicitement assignée, y compris `null`.
            'institution_id' => $user->institution_id,
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
        $count = 0;

        foreach ($users as $user) {
            $this->send($user, $type, $title, $message, $data);
            $count++;
        }

        return $count;
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
