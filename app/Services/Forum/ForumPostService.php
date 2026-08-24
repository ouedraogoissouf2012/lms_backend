<?php

declare(strict_types=1);

namespace App\Services\Forum;

use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\Notification;
use App\Models\User;

/**
 * Post-side orchestration du forum (split-18/forum).
 *
 * Extrait de `ForumController` : centralise les 4 endpoints "post"
 * (`storePost`, `updatePost`, `destroyPost`, `markAsSolution`) — création
 * scopée tenant, fan-out notifications inline (auteur du topic + auteur
 * du post parent), marquage solution, édition (flag `is_edited`).
 *
 * ## DI strict (§1.6 D)
 *
 * Aucune Facade — pure logique métier Eloquent. Pas de dépendance
 * constructeur : les FormRequests + le scope global `BelongsToInstitution`
 * portent la sécurité, le model `ForumPost` porte les compteurs.
 *
 * ## Comportement
 *
 * Le fan-out notifications se fait ici, en direct
 * (`Notification::create`), avec `institution_id` renseigné depuis
 * l'auteur. Il a existé un `ForumNotificationDispatcher` à la sémantique
 * différente (il notifiait tous les participants du topic, pas seulement
 * l'auteur du topic et celui du post parent) : jamais câblé, il a été
 * **supprimé par #500**. La sémantique qui fait foi est celle de ce
 * service.
 *
 * @see app/Http/Controllers/API/ForumController.php
 * @see app/Services/Forum/ForumTopicService.php
 */
final class ForumPostService
{
    /**
     * Colonnes publiques de l'auteur — `email` volontairement exclu (#544 —
     * PII, jamais exposée au client). Source unique pour tous les
     * `load()`/`fresh()` de ce service.
     */
    private const AUTHOR_COLUMNS = 'user:id,name,role';

    /**
     * Crée un post dans un topic et fan-out les notifications :
     *  1. Auteur du topic si distinct de l'auteur du post.
     *  2. Auteur du post parent (si `parent_id` fourni) si distinct des
     *     deux précédents.
     *
     * @param  array<string, mixed>  $data  Données validées
     *                                       (peut contenir `parent_id`).
     */
    public function create(array $data, ForumTopic $topic, User $author): ForumPost
    {
        $data['topic_id'] = $topic->id;
        $data['user_id'] = $author->id;
        $data['institution_id'] = $author->institution_id;

        $post = ForumPost::create($data);
        $post->load(self::AUTHOR_COLUMNS);

        // Compteur posts + activité du topic — appel explicite (remplace
        // l'ancien boot hook `ForumPost::created`, anti-pattern §5).
        $this->refreshTopicCounters($topic);

        // Créer une notification pour l'auteur du topic (sauf si c'est lui qui répond)
        if ($topic->user_id !== $author->id) {
            Notification::create([
                'user_id' => $topic->user_id,
                'type' => Notification::TYPE_FORUM_REPLY,
                'title' => 'Nouvelle réponse sur votre discussion',
                'message' => $author->name . ' a répondu à votre discussion "' . $topic->title . '"',
                'data' => [
                    'topic_id' => $topic->id,
                    'post_id' => $post->id,
                    'author_name' => $author->name,
                ],
                'institution_id' => $author->institution_id,
            ]);
        }

        // Si c'est une réponse à une réponse (parent_id existe)
        $parentId = $data['parent_id'] ?? null;
        if ($parentId) {
            $parentPost = ForumPost::find($parentId);

            if ($parentPost &&
                $parentPost->user_id !== $author->id &&
                $parentPost->user_id !== $topic->user_id) {

                Notification::create([
                    'user_id' => $parentPost->user_id,
                    'type' => Notification::TYPE_FORUM_REPLY,
                    'title' => 'Nouvelle réponse à votre message',
                    'message' => $author->name . ' a répondu à votre message dans "' . $topic->title . '"',
                    'data' => [
                        'topic_id' => $topic->id,
                        'post_id' => $post->id,
                        'parent_post_id' => $parentPost->id,
                        'author_name' => $author->name,
                    ],
                    'institution_id' => $author->institution_id,
                ]);
            }
        }

        return $post;
    }

    /**
     * Met à jour un post + marque flag `is_edited` (avec `edited_at`).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ForumPost $post, array $data): ForumPost
    {
        $post->update($data);
        $post->update(['is_edited' => true, 'edited_at' => now()]);

        /** @var ForumPost $fresh */
        $fresh = $post->fresh([self::AUTHOR_COLUMNS]);

        return $fresh;
    }

    /**
     * Supprime un post (soft delete) + recompte le topic (remplace l'ancien
     * boot hook `ForumPost::deleted`).
     */
    public function delete(ForumPost $post): void
    {
        $topic = $post->topic;
        $post->delete();

        if ($topic !== null) {
            $this->refreshTopicCounters($topic);
        }
    }

    /**
     * Marquer un post comme solution : démarque les autres posts solution du
     * topic, marque ce post, passe le topic en résolu. Fan-out notification
     * à l'auteur du post (sauf self-marking).
     */
    public function markAsSolution(ForumPost $post, User $markedBy): ForumPost
    {
        $topic = $post->topic;

        // Un seul post solution par topic.
        $topic->posts()->where('id', '!=', $post->id)->update(['is_solution' => false]);
        $post->update(['is_solution' => true]);
        $topic->update(['is_resolved' => true]);

        // Créer une notification pour l'auteur du post (sauf si c'est lui qui marque)
        if ($post->user_id !== $markedBy->id) {
            Notification::create([
                'user_id' => $post->user_id,
                'type' => Notification::TYPE_FORUM_SOLUTION,
                'title' => 'Votre réponse a été acceptée',
                'message' => 'Votre réponse dans "' . $topic->title . '" a été marquée comme solution',
                'data' => [
                    'topic_id' => $topic->id,
                    'post_id' => $post->id,
                    'marked_by_name' => $markedBy->name,
                ],
                'institution_id' => $markedBy->institution_id,
            ]);
        }

        /** @var ForumPost $fresh */
        $fresh = $post->fresh([self::AUTHOR_COLUMNS]);

        return $fresh;
    }

    /**
     * Recompte `posts_count` + horodate `last_activity_at` du topic.
     * Appel explicite à chaque create/delete de post — remplace les boot
     * hooks `ForumPost::created/deleted` (anti-pattern §5, cf. fix C1/C2).
     */
    private function refreshTopicCounters(ForumTopic $topic): void
    {
        $topic->posts_count = $topic->posts()->count();
        $topic->last_activity_at = now();
        $topic->save();
    }
}
