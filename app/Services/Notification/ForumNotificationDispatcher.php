<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\ForumPost;
use App\Models\Notification;
use App\Models\User;

/**
 * Notifications liées au forum (réponse, solution acceptée).
 *
 * Extrait de `NotificationService` (split notification-service, §1.1 ≤300 l).
 *
 * ## DI strict (§1.6 D)
 *
 * Délègue l'envoi bas niveau à `NotificationDispatcher` injecté.
 *
 * @see app/Services/NotificationService.php  Facade orchestrateur
 */
final class ForumNotificationDispatcher
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {
    }

    /**
     * Notifier l'auteur du topic et les participants d'une nouvelle réponse.
     */
    public function notifyForumReply(ForumPost $post): int
    {
        $topic = $post->topic;
        $author = $topic->user;

        // Ne pas notifier si l'auteur du post est aussi l'auteur du topic
        if ($post->user_id === $author->id) {
            return 0;
        }

        $title = "Nouvelle réponse à votre topic";
        $message = "{$post->user->name} a répondu à votre topic \"{$topic->title}\".";
        $data = [
            'topic_id' => $topic->id,
            'post_id' => $post->id,
            'topic_title' => $topic->title,
            'replier_name' => $post->user->name,
        ];

        $this->dispatcher->send($author, Notification::TYPE_FORUM_REPLY, $title, $message, $data);

        // Notifier aussi les participants du topic (qui ont posté)
        $participants = User::query()
            ->whereHas('forumPosts', function ($query) use ($topic) {
                $query->where('topic_id', $topic->id);
            })
            ->where('id', '!=', $author->id) // Pas l'auteur du topic
            ->where('id', '!=', $post->user_id) // Pas l'auteur de ce post
            ->get();

        if ($participants->isNotEmpty()) {
            $participantTitle = "Nouvelle réponse";
            $participantMessage = "{$post->user->name} a répondu au topic \"{$topic->title}\".";

            return 1 + $this->dispatcher->sendToMany($participants, Notification::TYPE_FORUM_REPLY, $participantTitle, $participantMessage, $data);
        }

        return 1;
    }

    /**
     * Notifier qu'un post a été marqué comme solution.
     */
    public function notifyForumSolution(ForumPost $post): int
    {
        $topic = $post->topic;
        $postAuthor = $post->user;

        $title = "Votre réponse a été marquée comme solution";
        $message = "Votre réponse au topic \"{$topic->title}\" a été acceptée comme solution !";
        $data = [
            'topic_id' => $topic->id,
            'post_id' => $post->id,
            'topic_title' => $topic->title,
        ];

        return $this->dispatcher->send($postAuthor, Notification::TYPE_FORUM_SOLUTION, $title, $message, $data) ? 1 : 0;
    }
}
