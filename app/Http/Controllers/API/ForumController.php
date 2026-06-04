<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\CloseTopicRequest;
use App\Http\Requests\DeleteForumPostRequest;
use App\Http\Requests\DeleteForumTopicRequest;
use App\Http\Requests\MarkPostAsSolutionRequest;
use App\Http\Requests\PinTopicRequest;
use App\Http\Requests\StoreForumPostRequest;
use App\Http\Requests\StoreForumTopicRequest;
use App\Http\Requests\UpdateForumPostRequest;
use App\Http\Requests\UpdateForumTopicRequest;
use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Services\Forum\ForumPostService;
use App\Services\Forum\ForumTopicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller — endpoints forum (topics + posts).
 *
 * Refactor split-18/forum : la logique métier (filtres + mutations +
 * fan-out notifications) est déléguée à deux services SRP
 * (`ForumTopicService`, `ForumPostService`). Le controller se limite
 * à : extraire l'input, déléguer, formater la réponse HTTP.
 *
 * §5 ≤200 lignes — §1.6 D DI strict (services injectés par constructeur,
 * pas de `app()`, pas de Facade `Log`).
 */
final class ForumController extends AuthenticatedController
{
    public function __construct(
        private readonly ForumTopicService $topicService,
        private readonly ForumPostService $postService,
    ) {
    }

    /**
     * GET /api/forum/topics — liste des topics (avec filtres).
     */
    public function index(Request $request): JsonResponse
    {
        $topics = $this->topicService->list($request);

        return response()->json([
            'success' => true,
            'data' => $topics,
        ]);
    }

    /**
     * POST /api/forum/topics — créer un nouveau topic.
     */
    public function store(StoreForumTopicRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $topic = $this->topicService->create($request->validated(), $user);

        return response()->json([
            'success' => true,
            'message' => 'Topic créé avec succès',
            'data' => $topic,
        ], 201);
    }

    /**
     * GET /api/forum/topics/{topic} — détails d'un topic avec ses posts.
     */
    public function show(Request $request, ForumTopic $topic): JsonResponse
    {
        $topic = $this->topicService->showWithPosts($topic);

        return response()->json([
            'success' => true,
            'data' => $topic,
        ]);
    }

    /**
     * PUT /api/forum/topics/{topic} — mettre à jour un topic.
     */
    public function update(UpdateForumTopicRequest $request, ForumTopic $topic): JsonResponse
    {
        $topic = $this->topicService->update($topic, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Topic mis à jour',
            'data' => $topic,
        ]);
    }

    /**
     * DELETE /api/forum/topics/{topic} — supprimer un topic.
     */
    public function destroy(DeleteForumTopicRequest $request, ForumTopic $topic): JsonResponse
    {
        $this->topicService->delete($topic);

        return response()->json([
            'success' => true,
            'message' => 'Topic supprimé',
        ]);
    }

    /**
     * POST /api/forum/topics/{topic}/posts — ajouter un post à un topic.
     */
    public function storePost(StoreForumPostRequest $request, ForumTopic $topic): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $post = $this->postService->create($request->validated(), $topic, $user);

        return response()->json([
            'success' => true,
            'message' => 'Post ajouté',
            'data' => $post,
        ], 201);
    }

    /**
     * PUT /api/forum/posts/{post} — modifier un post.
     */
    public function updatePost(UpdateForumPostRequest $request, ForumPost $post): JsonResponse
    {
        $post = $this->postService->update($post, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Post mis à jour',
            'data' => $post,
        ]);
    }

    /**
     * DELETE /api/forum/posts/{post} — supprimer un post.
     */
    public function destroyPost(DeleteForumPostRequest $request, ForumPost $post): JsonResponse
    {
        $this->postService->delete($post);

        return response()->json([
            'success' => true,
            'message' => 'Post supprimé',
        ]);
    }

    /**
     * POST /api/forum/posts/{post}/solution — marquer un post comme solution.
     */
    public function markAsSolution(MarkPostAsSolutionRequest $request, ForumPost $post): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $post = $this->postService->markAsSolution($post, $user);

        return response()->json([
            'success' => true,
            'message' => 'Post marqué comme solution',
            'data' => $post,
        ]);
    }

    /**
     * POST /api/forum/topics/{topic}/close — fermer un topic.
     */
    public function closeTopic(CloseTopicRequest $request, ForumTopic $topic): JsonResponse
    {
        $topic = $this->topicService->close($topic);

        return response()->json([
            'success' => true,
            'message' => 'Topic fermé',
            'data' => $topic,
        ]);
    }

    /**
     * POST /api/forum/topics/{topic}/pin — épingler un topic.
     */
    public function pinTopic(PinTopicRequest $request, ForumTopic $topic): JsonResponse
    {
        $topic = $this->topicService->pin($topic);

        return response()->json([
            'success' => true,
            'message' => 'Topic épinglé',
            'data' => $topic,
        ]);
    }
}
