<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ForumTopic;
use App\Models\ForumPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'lesson_id' => 'nullable|exists:lessons,id',
            'matiere_id' => 'nullable|integer',
            'classe_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['user_id'] = $request->user()->id;
        $data['last_activity_at'] = now();

        $topic = ForumTopic::create($data);

        // Charger les relations
        $topic->load('user:id,name,email,role');

        return response()->json([
            'success' => true,
            'message' => 'Topic créé avec succès',
            'data' => $topic,
        ], 201);
    }

    /**
     * GET /api/forum/topics/{id}
     * Détails d'un topic avec ses posts
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $topic = ForumTopic::with([
            'user:id,name,email,role',
            'lesson:id,title',
            'posts' => function ($query) {
                $query->rootLevel()
                    ->with(['user:id,name,email,role', 'replies.user:id,name,email,role'])
                    ->orderBy('is_solution', 'desc')
                    ->orderBy('created_at', 'asc');
            }
        ])->find($id);

        if (!$topic) {
            return response()->json([
                'success' => false,
                'message' => 'Topic non trouvé',
            ], 404);
        }

        // Incrémenter le compteur de vues
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
    public function update(Request $request, int $id): JsonResponse
    {
        $topic = ForumTopic::find($id);

        if (!$topic) {
            return response()->json([
                'success' => false,
                'message' => 'Topic non trouvé',
            ], 404);
        }

        // Vérifier les permissions
        $user = $request->user();
        if (!$user->isAdmin() && $topic->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à modifier ce topic',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        $topic->update($validator->validated());

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
    public function destroy(Request $request, int $id): JsonResponse
    {
        $topic = ForumTopic::find($id);

        if (!$topic) {
            return response()->json([
                'success' => false,
                'message' => 'Topic non trouvé',
            ], 404);
        }

        // Vérifier les permissions
        $user = $request->user();
        if (!$user->isAdmin() && $topic->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à supprimer ce topic',
            ], 403);
        }

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
    public function storePost(Request $request, int $id): JsonResponse
    {
        $topic = ForumTopic::find($id);

        if (!$topic) {
            return response()->json([
                'success' => false,
                'message' => 'Topic non trouvé',
            ], 404);
        }

        // Vérifier si le topic est fermé
        if ($topic->isClosed() && !$request->user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce topic est fermé',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'required|string',
            'parent_id' => 'nullable|exists:forum_posts,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['topic_id'] = $topic->id;
        $data['user_id'] = $request->user()->id;

        $post = ForumPost::create($data);
        $post->load('user:id,name,email,role');

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
    public function updatePost(Request $request, int $id): JsonResponse
    {
        $post = ForumPost::find($id);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post non trouvé',
            ], 404);
        }

        // Vérifier les permissions
        $user = $request->user();
        if (!$user->isAdmin() && $post->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à modifier ce post',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        $post->update($validator->validated());
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
    public function destroyPost(Request $request, int $id): JsonResponse
    {
        $post = ForumPost::find($id);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post non trouvé',
            ], 404);
        }

        // Vérifier les permissions
        $user = $request->user();
        if (!$user->isAdmin() && $post->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à supprimer ce post',
            ], 403);
        }

        $post->delete();

        return response()->json([
            'success' => true,
            'message' => 'Post supprimé',
        ]);
    }

    /**
     * POST /api/forum/posts/{id}/solution
     * Marquer un post comme solution (Enseignants/Auteur du topic)
     */
    public function markAsSolution(Request $request, int $id): JsonResponse
    {
        $post = ForumPost::find($id);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post non trouvé',
            ], 404);
        }

        $user = $request->user();
        $topic = $post->topic;

        // Seul l'auteur du topic, un enseignant ou un admin peut marquer une solution
        if (!$user->isAdmin() && !$user->isTeacher() && $topic->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à marquer une solution',
            ], 403);
        }

        $post->markAsSolution();

        return response()->json([
            'success' => true,
            'message' => 'Post marqué comme solution',
            'data' => $post->fresh(['user']),
        ]);
    }

    /**
     * POST /api/forum/topics/{id}/close
     * Fermer un topic (Enseignants/Coordinateurs/Admin)
     */
    public function closeTopic(Request $request, int $id): JsonResponse
    {
        $topic = ForumTopic::find($id);

        if (!$topic) {
            return response()->json([
                'success' => false,
                'message' => 'Topic non trouvé',
            ], 404);
        }

        $topic->close();

        return response()->json([
            'success' => true,
            'message' => 'Topic fermé',
            'data' => $topic,
        ]);
    }

    /**
     * POST /api/forum/topics/{id}/pin
     * Épingler un topic (Enseignants/Coordinateurs/Admin)
     */
    public function pinTopic(Request $request, int $id): JsonResponse
    {
        $topic = ForumTopic::find($id);

        if (!$topic) {
            return response()->json([
                'success' => false,
                'message' => 'Topic non trouvé',
            ], 404);
        }

        $topic->pin();

        return response()->json([
            'success' => true,
            'message' => 'Topic épinglé',
            'data' => $topic,
        ]);
    }
}
