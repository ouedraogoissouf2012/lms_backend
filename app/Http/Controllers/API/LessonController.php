<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Controller pour la gestion des cours (Lessons)
 */
class LessonController extends Controller
{
    /**
     * GET /api/lessons
     * Liste des cours (avec filtres optionnels)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Lesson::query();

        // Filtres
        if ($request->has('matiere_id')) {
            $query->forMatiere($request->matiere_id);
        }

        if ($request->has('classe_id')) {
            $query->forClasse($request->classe_id);
        }

        if ($request->has('enseignant_id')) {
            $query->byTeacher($request->enseignant_id);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Seuls les cours publiés pour les étudiants
        $user = $request->user();
        if ($user->isStudent()) {
            $query->published();
        } elseif ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Pagination
        $perPage = $request->get('per_page', 15);
        $lessons = $query->ordered()->paginate($perPage);

        // Ajouter la progression pour chaque cours (si étudiant)
        if ($user->isStudent()) {
            $lessons->getCollection()->transform(function ($lesson) use ($user) {
                $progress = $lesson->progressForUser($user->id);
                $lesson->user_progress = $progress;
                return $lesson;
            });
        }

        return response()->json([
            'success' => true,
            'data' => $lessons,
        ]);
    }

    /**
     * GET /api/lessons/{id}
     * Détails d'un cours
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $lesson = Lesson::find($id);

        if (!$lesson) {
            return response()->json([
                'success' => false,
                'message' => 'Cours non trouvé',
            ], 404);
        }

        // Vérifier les permissions
        if ($user->isStudent() && !$lesson->isPublished()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce cours n\'est pas encore disponible',
            ], 403);
        }

        // Charger la progression de l'utilisateur
        $progress = $lesson->progressForUser($user->id);
        $lesson->user_progress = $progress;

        // Statistiques (pour enseignants uniquement)
        if ($user->isTeacher() || $user->isCoordinator() || $user->isAdmin()) {
            $lesson->statistics = [
                'students_started' => $lesson->getStudentsStartedCount(),
                'students_completed' => $lesson->getStudentsCompletedCount(),
                'average_completion_rate' => round($lesson->getAverageCompletionRate(), 2),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $lesson,
        ]);
    }

    /**
     * POST /api/lessons
     * Créer un nouveau cours (Enseignants uniquement)
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'content' => 'nullable|string',
            'type' => 'required|in:cours,tp,td,projet,autre',
            'matiere_id' => 'nullable|integer',
            'classe_id' => 'nullable|integer',
            'chapter_id' => 'nullable|integer|exists:chapters,id',
            'content_type' => 'nullable|in:text,video,pdf,audio,presentation,link,mixed',
            'video_url' => 'nullable|string',
            'video_provider' => 'nullable|in:youtube,vimeo,local,other',
            'pdf_url' => 'nullable|string',
            'audio_url' => 'nullable|string',
            'presentation_url' => 'nullable|string',
            'external_link' => 'nullable|string',
            'duration_minutes' => 'nullable|integer|min:1',
            'status' => 'nullable|in:draft,published,archived',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['enseignant_id'] = $request->user()->klassci_id;

        // Si la leçon est créée avec status "published", définir published_at automatiquement
        if (isset($data['status']) && $data['status'] === 'published' && !isset($data['published_at'])) {
            $data['published_at'] = now();
        }

        $lesson = Lesson::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Cours créé avec succès',
            'data' => $lesson,
        ], 201);
    }

    /**
     * PUT /api/lessons/{id}
     * Mettre à jour un cours (Enseignants uniquement)
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $lesson = Lesson::find($id);

        if (!$lesson) {
            return response()->json([
                'success' => false,
                'message' => 'Cours non trouvé',
            ], 404);
        }

        // Vérifier que l'utilisateur est propriétaire ou admin
        $user = $request->user();
        if (!$user->isAdmin() && $lesson->enseignant_id !== $user->klassci_id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à modifier ce cours',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'content' => 'nullable|string',
            'type' => 'sometimes|in:cours,tp,td,projet,autre',
            'matiere_id' => 'nullable|integer',
            'classe_id' => 'nullable|integer',
            'chapter_id' => 'nullable|integer|exists:chapters,id',
            'content_type' => 'sometimes|in:text,video,pdf,audio,presentation,link,mixed',
            'video_url' => 'nullable|string',
            'video_provider' => 'nullable|in:youtube,vimeo,local,other',
            'pdf_url' => 'nullable|string',
            'audio_url' => 'nullable|string',
            'presentation_url' => 'nullable|string',
            'external_link' => 'nullable|string',
            'duration_minutes' => 'nullable|integer|min:1',
            'status' => 'sometimes|in:draft,published,archived',
            'order' => 'sometimes|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Si le statut passe à "published" et que published_at n'est pas défini, le définir maintenant
        if (isset($data['status']) && $data['status'] === 'published' && !$lesson->published_at) {
            $data['published_at'] = now();
        }

        // Si le statut passe à "draft" ou "archived", retirer published_at
        if (isset($data['status']) && in_array($data['status'], ['draft', 'archived'])) {
            $data['published_at'] = null;
        }

        $lesson->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Cours mis à jour avec succès',
            'data' => $lesson,
        ]);
    }

    /**
     * DELETE /api/lessons/{id}
     * Supprimer un cours (Enseignants uniquement)
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $lesson = Lesson::find($id);

        if (!$lesson) {
            return response()->json([
                'success' => false,
                'message' => 'Cours non trouvé',
            ], 404);
        }

        // Vérifier que l'utilisateur est propriétaire ou admin
        $user = $request->user();
        if (!$user->isAdmin() && $lesson->enseignant_id !== $user->klassci_id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à supprimer ce cours',
            ], 403);
        }

        $lesson->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cours supprimé avec succès',
        ]);
    }

    /**
     * POST /api/lessons/{id}/publish
     * Publier un cours (Enseignants uniquement)
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        $lesson = Lesson::find($id);

        if (!$lesson) {
            return response()->json([
                'success' => false,
                'message' => 'Cours non trouvé',
            ], 404);
        }

        $user = $request->user();
        if (!$user->isAdmin() && $lesson->enseignant_id !== $user->klassci_id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à publier ce cours',
            ], 403);
        }

        $wasUnpublished = $lesson->status === 'draft';
        $lesson->publish();

        // Créer des notifications pour les étudiants concernés si le cours vient d'être publié
        if ($wasUnpublished && $lesson->matiere_id) {
            // Récupérer tous les étudiants de la matière via KLASSCI
            try {
                $klassciService = app(\App\Services\KlassciProxyService::class);
                $matiereData = $klassciService->request('GET', "/matieres/{$lesson->matiere_id}");

                if (isset($matiereData['data']['classe_ids']) && is_array($matiereData['data']['classe_ids'])) {
                    $studentIds = [];
                    foreach ($matiereData['data']['classe_ids'] as $classeId) {
                        $classeData = $klassciService->request('GET', "/classes/{$classeId}");
                        if (isset($classeData['data']['etudiant_ids']) && is_array($classeData['data']['etudiant_ids'])) {
                            $studentIds = array_merge($studentIds, $classeData['data']['etudiant_ids']);
                        }
                    }

                    // Créer notification pour chaque étudiant
                    $studentIds = array_unique($studentIds);
                    foreach ($studentIds as $klassciId) {
                        // Trouver l'utilisateur local correspondant
                        $student = \App\Models\User::where('klassci_id', $klassciId)->first();
                        if ($student) {
                            \App\Models\Notification::create([
                                'user_id' => $student->id,
                                'type' => \App\Models\Notification::TYPE_LESSON_PUBLISHED,
                                'title' => 'Nouveau cours disponible',
                                'message' => 'Un nouveau cours "' . $lesson->titre . '" est maintenant disponible',
                                'data' => [
                                    'lesson_id' => $lesson->id,
                                    'matiere_id' => $lesson->matiere_id,
                                ],
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                // Si erreur KLASSCI, on continue quand même
                \Log::warning('Erreur lors de la création des notifications de cours: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Cours publié avec succès',
            'data' => $lesson,
        ]);
    }

    /**
     * POST /api/lessons/{id}/unpublish
     * Dépublier un cours (Enseignants uniquement)
     */
    public function unpublish(Request $request, int $id): JsonResponse
    {
        $lesson = Lesson::find($id);

        if (!$lesson) {
            return response()->json([
                'success' => false,
                'message' => 'Cours non trouvé',
            ], 404);
        }

        $user = $request->user();
        if (!$user->isAdmin() && $lesson->enseignant_id !== $user->klassci_id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à dépublier ce cours',
            ], 403);
        }

        $lesson->unpublish();

        return response()->json([
            'success' => true,
            'message' => 'Cours remis en brouillon',
            'data' => $lesson,
        ]);
    }

    /**
     * GET /api/lessons/{id}/progress
     * Obtenir la progression d'un cours (ou de tous les étudiants pour enseignant)
     */
    public function getProgress(Request $request, int $id): JsonResponse
    {
        $lesson = Lesson::find($id);

        if (!$lesson) {
            return response()->json([
                'success' => false,
                'message' => 'Cours non trouvé',
            ], 404);
        }

        $user = $request->user();

        // Étudiant: sa propre progression
        if ($user->isStudent()) {
            $progress = $lesson->progressForUser($user->id);

            if (!$progress) {
                // Créer une nouvelle progression
                $progress = LessonProgress::create([
                    'user_id' => $user->id,
                    'lesson_id' => $lesson->id,
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $progress,
            ]);
        }

        // Enseignant: progression de tous les étudiants
        $progresses = $lesson->progress()->with('user')->get();

        return response()->json([
            'success' => true,
            'data' => $progresses,
            'statistics' => [
                'total_students' => $progresses->count(),
                'students_started' => $lesson->getStudentsStartedCount(),
                'students_completed' => $lesson->getStudentsCompletedCount(),
                'average_completion_rate' => round($lesson->getAverageCompletionRate(), 2),
            ],
        ]);
    }

    /**
     * POST /api/lessons/{id}/progress
     * Mettre à jour sa progression (Étudiants uniquement)
     */
    public function updateProgress(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'progress_percentage' => 'required|integer|min:0|max:100',
            'time_spent_minutes' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        $lesson = Lesson::find($id);

        if (!$lesson) {
            return response()->json([
                'success' => false,
                'message' => 'Cours non trouvé',
            ], 404);
        }

        $user = $request->user();

        // Trouver ou créer la progression
        $progress = LessonProgress::firstOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id]
        );

        $progress->updateProgress(
            $request->progress_percentage,
            $request->time_spent_minutes
        );

        return response()->json([
            'success' => true,
            'message' => 'Progression mise à jour',
            'data' => $progress->fresh(),
        ]);
    }

    /**
     * POST /api/lessons/{id}/complete
     * Marquer un cours comme complété (Étudiants uniquement)
     */
    public function markComplete(Request $request, int $id): JsonResponse
    {
        $lesson = Lesson::find($id);

        if (!$lesson) {
            return response()->json([
                'success' => false,
                'message' => 'Cours non trouvé',
            ], 404);
        }

        $user = $request->user();

        $progress = LessonProgress::firstOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id]
        );

        $progress->complete();

        return response()->json([
            'success' => true,
            'message' => 'Cours marqué comme complété',
            'data' => $progress->fresh(),
        ]);
    }

    /**
     * POST /api/lessons/{id}/rating
     * Noter un cours (Étudiants uniquement)
     */
    public function rate(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rating' => 'required|integer|min:1|max:5',
            'feedback' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        $lesson = Lesson::find($id);

        if (!$lesson) {
            return response()->json([
                'success' => false,
                'message' => 'Cours non trouvé',
            ], 404);
        }

        $user = $request->user();

        $progress = LessonProgress::firstOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id]
        );

        $progress->addRating($request->rating, $request->feedback);

        return response()->json([
            'success' => true,
            'message' => 'Note enregistrée',
            'data' => $progress->fresh(),
        ]);
    }
}
