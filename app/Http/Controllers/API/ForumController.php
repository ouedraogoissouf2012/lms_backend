<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreForumTopicRequest;
use App\Http\Requests\UpdateForumTopicRequest;
use App\Http\Requests\DeleteForumTopicRequest;
use App\Http\Requests\StoreForumPostRequest;
use App\Http\Requests\UpdateForumPostRequest;
use App\Http\Requests\DeleteForumPostRequest;
use App\Http\Requests\MarkPostAsSolutionRequest;
use App\Http\Requests\CloseTopicRequest;
use App\Http\Requests\PinTopicRequest;
use App\Models\ForumTopic;
use App\Models\ForumPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller pour le forum de discussion
 */
class ForumController extends Controller
{
    /**
     * GET /api/forum/topics
     * Liste des topics (avec filtres)
     */
    public function index(Request $request): JsonResponse
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
        $topics = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $topics,
        ]);
    }

    /**
     * POST /api/forum/topics
     * Créer un nouveau topic
     */
    public function store(StoreForumTopicRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->id;
        $data['institution_id'] = $request->user()->institution_id;
        $data['last_activity_at'] = now();

        $topic = ForumTopic::create($data);
        $topic->load('user:id,name,email,role');

        return response()->json([
            'success' => true,
            'message' => 'Topic créé avec succès',
            'data' => $topic,
        ], 201);
    }

    /**
     * GET /api/forum/topics/{topic}
     * Détails d'un topic avec ses posts
     */
    public function show(Request $request, ForumTopic $topic): JsonResponse
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

        return response()->json([
            'success' => true,
            'data' => $topic,
        ]);
    }

    /**
     * PUT /api/forum/topics/{id}
     * Mettre à jour un topic
     */
    public function update(UpdateForumTopicRequest $request, ForumTopic $topic): JsonResponse
    {
        $topic->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Topic mis à jour',
            'data' => $topic->fresh(['user']),
        ]);
    }

    /**
     * DELETE /api/forum/topics/{id}
     * Supprimer un topic
     */
    public function destroy(DeleteForumTopicRequest $request, ForumTopic $topic): JsonResponse
    {
        $topic->delete();

        return response()->json([
            'success' => true,
            'message' => 'Topic supprimé',
        ]);
    }

    /**
     * POST /api/forum/topics/{id}/posts
     * Ajouter un post à un topic
     */
    public function storePost(StoreForumPostRequest $request, ForumTopic $topic): JsonResponse
    {
        $data = $request->validated();
        $data['topic_id'] = $topic->id;
        $data['user_id'] = $request->user()->id;
        $data['institution_id'] = $request->user()->institution_id;

        $post = ForumPost::create($data);
        $post->load('user:id,name,email,role');

        // Créer une notification pour l'auteur du topic (sauf si c'est lui qui répond)
        if ($topic->user_id !== $request->user()->id) {
            \App\Models\Notification::create([
                'user_id' => $topic->user_id,
                'type' => \App\Models\Notification::TYPE_FORUM_REPLY,
                'title' => 'Nouvelle réponse sur votre discussion',
                'message' => $request->user()->name . ' a répondu à votre discussion "' . $topic->title . '"',
                'data' => [
                    'topic_id' => $topic->id,
                    'post_id' => $post->id,
                    'author_name' => $request->user()->name,
                ],
                'institution_id' => $request->user()->institution_id,
            ]);
        }

        // Si c'est une réponse à une réponse (parent_id existe)
        if ($request->parent_id) {
            $parentPost = ForumPost::find($request->parent_id);

            if ($parentPost &&
                $parentPost->user_id !== $request->user()->id &&
                $parentPost->user_id !== $topic->user_id) {

                \App\Models\Notification::create([
                    'user_id' => $parentPost->user_id,
                    'type' => \App\Models\Notification::TYPE_FORUM_REPLY,
                    'title' => 'Nouvelle réponse à votre message',
                    'message' => $request->user()->name . ' a répondu à votre message dans "' . $topic->title . '"',
                    'data' => [
                        'topic_id' => $topic->id,
                        'post_id' => $post->id,
                        'parent_post_id' => $parentPost->id,
                        'author_name' => $request->user()->name,
                    ],
                    'institution_id' => $request->user()->institution_id,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Post ajouté',
            'data' => $post,
        ], 201);
    }

    /**
     * PUT /api/forum/posts/{id}
     * Modifier un post
     */
    public function updatePost(UpdateForumPostRequest $request, ForumPost $post): JsonResponse
    {
        $post->update($request->validated());
        $post->markAsEdited();

        return response()->json([
            'success' => true,
            'message' => 'Post mis à jour',
            'data' => $post->fresh(['user']),
        ]);
    }

    /**
     * DELETE /api/forum/posts/{id}
     * Supprimer un post
     */
    public function destroyPost(DeleteForumPostRequest $request, ForumPost $post): JsonResponse
    {
        $post->delete();

        return response()->json([
            'success' => true,
            'message' => 'Post supprimé',
        ]);
    }

    /**
     * POST /api/forum/posts/{id}/solution
     * Marquer un post comme solution
     */
    public function markAsSolution(MarkPostAsSolutionRequest $request, ForumPost $post): JsonResponse
    {
        $user = $request->user();
        $topic = $post->topic;

        $post->markAsSolution();

        // Créer une notification pour l'auteur du post (sauf si c'est lui qui marque)
        if ($post->user_id !== $user->id) {
            \App\Models\Notification::create([
                'user_id' => $post->user_id,
                'type' => \App\Models\Notification::TYPE_FORUM_SOLUTION,
                'title' => 'Votre réponse a été acceptée',
                'message' => 'Votre réponse dans "' . $topic->title . '" a été marquée comme solution',
                'data' => [
                    'topic_id' => $topic->id,
                    'post_id' => $post->id,
                    'marked_by_name' => $user->name,
                ],
                'institution_id' => $user->institution_id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Post marqué comme solution',
            'data' => $post->fresh(['user']),
        ]);
    }

    /**
     * POST /api/forum/topics/{id}/close
     * Fermer un topic
     */
    public function closeTopic(CloseTopicRequest $request, ForumTopic $topic): JsonResponse
    {
        $topic->close();

        return response()->json([
            'success' => true,
            'message' => 'Topic fermé',
            'data' => $topic,
        ]);
    }

    /**
     * POST /api/forum/topics/{id}/pin
     * Épingler un topic
     */
    public function pinTopic(PinTopicRequest $request, ForumTopic $topic): JsonResponse
    {
        $topic->pin();

        return response()->json([
            'success' => true,
            'message' => 'Topic épinglé',
            'data' => $topic,
        ]);
    }
}
