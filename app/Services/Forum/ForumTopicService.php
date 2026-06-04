<?php

declare(strict_types=1);

namespace App\Services\Forum;

use App\Models\ForumTopic;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * Topic-side orchestration du forum (split-18/forum).
 *
 * Extrait de `ForumController` : centralise les 7 endpoints "topic"
 * (`index`, `store`, `show`, `update`, `destroy`, `closeTopic`,
 * `pinTopic`) — construction de la query filtrée, création scopée
 * tenant, hydratation profonde pour `show`, et transitions de statut.
 *
 * ## DI strict (§1.6 D)
 *
 * Aucune Facade — pure logique métier Eloquent. Pas de dépendance
 * constructeur car ce service ne logge ni n'orchestre d'autres
 * services (les notifications de réponse sont du domaine `ForumPost`).
 *
 * ## Comportement
 *
 * Aucun changement runtime : logique déplacée verbatim depuis le
 * god-controller (split-18). Les FormRequests + middleware
 * `role:...` portent l'autorisation en amont — le service est appelé
 * uniquement après une autorisation valide.
 *
 * @see app/Http/Controllers/API/ForumController.php
 * @see app/Services/Forum/ForumPostService.php
 */
final class ForumTopicService
{
    /**
     * GET /api/forum/topics — liste paginée filtrée et triée.
     *
     * Filtres : `lesson_id`, `matiere_id`, `classe_id`, `status`,
     * `resolved`. Tri : `activity` (défaut), `recent`, `popular`.
     */
    public function list(Request $request): LengthAwarePaginator
    {
        $query = ForumTopic::with(['user:id,name,email,role']);

        // Filtres
        if ($request->has('lesson_id')) {
            $query->forLesson($request->lesson_id);
        }

        if ($request->has('matiere_id')) {
            $query->forMatiere($request->matiere_id);
        }

        if ($request->has('classe_id')) {
            $query->forClasse($request->classe_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('resolved')) {
            $query->where('is_resolved', $request->boolean('resolved'));
        }

        // Tri
        $sortBy = $request->get('sort', 'activity'); // activity, recent, popular
        switch ($sortBy) {
            case 'recent':
                $query->orderBy('created_at', 'desc');
                break;
            case 'popular':
                $query->orderBy('views_count', 'desc');
                break;
            default: // activity
                $query->byActivity();
        }

        $perPage = $request->get('per_page', 15);

        return $query->paginate($perPage);
    }

    /**
     * Créer un topic — injecte `user_id`, `institution_id` et
     * `last_activity_at`, recharge l'auteur pour la sérialisation.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $author): ForumTopic
    {
        $data['user_id'] = $author->id;
        $data['institution_id'] = $author->institution_id;
        $data['last_activity_at'] = now();

        $topic = ForumTopic::create($data);
        $topic->load('user:id,name,email,role');

        return $topic;
    }

    /**
     * Hydrate le topic avec auteur + leçon + posts racine triés
     * (solutions d'abord, puis chronologique), incrémente le compteur
     * de vues, et retourne le topic prêt à sérialiser.
     */
    public function showWithPosts(ForumTopic $topic): ForumTopic
    {
        $topic->load([
            'user:id,name,email,role',
            'lesson:id,title',
            'posts' => function ($query) {
                $query->rootLevel()
                    ->with(['user:id,name,email,role', 'replies.user:id,name,email,role'])
                    ->orderBy('is_solution', 'desc')
                    ->orderBy('created_at', 'asc');
            }
        ]);

        $topic->incrementViews();

        return $topic;
    }

    /**
     * Mettre à jour un topic puis renvoyer une instance fraîche avec
     * l'auteur hydraté pour la sérialisation.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ForumTopic $topic, array $data): ForumTopic
    {
        $topic->update($data);

        /** @var ForumTopic $fresh */
        $fresh = $topic->fresh(['user']);

        return $fresh;
    }

    /**
     * Supprime un topic (soft delete via `SoftDeletes`).
     */
    public function delete(ForumTopic $topic): void
    {
        $topic->delete();
    }

    /**
     * Fermer un topic (statut → `closed`).
     */
    public function close(ForumTopic $topic): ForumTopic
    {
        $topic->close();

        return $topic;
    }

    /**
     * Épingler un topic (statut → `pinned`).
     */
    public function pin(ForumTopic $topic): ForumTopic
    {
        $topic->pin();

        return $topic;
    }
}
