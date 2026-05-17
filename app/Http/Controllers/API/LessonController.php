<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\FilterLessonsRequest;
use App\Http\Requests\StoreLessonRequest;
use App\Http\Requests\UpdateLessonRequest;
use App\Http\Requests\DeleteLessonRequest;
use App\Http\Requests\PublishLessonRequest;
use App\Http\Requests\UnpublishLessonRequest;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Controller pour la gestion des cours (Lessons)
 */
class LessonController extends AuthenticatedController
{
    /**
     * GET /api/lessons
     * Liste des cours (avec filtres optionnels)
     */
    public function index(FilterLessonsRequest $request): JsonResponse
    {
        $query = Lesson::with(['matiere', 'enseignant', 'classe']);

        // Filtres — validated via FilterLessonsRequest
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
        $user = $this->authenticatedUser($request);
        if ($user->isStudent()) {
            $query->published();
        } elseif ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Pagination — DOS-protected by FilterLessonsRequest (per_page: 1-100)
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
     * GET /api/lessons/my-courses
     * Liste des cours de l'étudiant connecté avec enseignant et matière
     * Filtrable par matiere_id et enseignant_id
     */
    public function myCourses(Request $request): JsonResponse
    {
        // $user guaranteed non-null by `authenticatedUser()` (throws AuthenticationException → 401 otherwise)
        $user = $this->authenticatedUser($request);

        // Construire la requête - Tous les cours publiés
        $query = Lesson::with(['matiere', 'classe'])
            ->published()
            ->ordered();

        // Filtres optionnels
        if ($request->has('matiere_id')) {
            $query->forMatiere($request->matiere_id);
        }

        if ($request->has('enseignant_id')) {
            // Chercher l'user par klassci_id (car le frontend envoie klassci_id)
            $enseignantUser = \App\Models\User::where('klassci_id', $request->enseignant_id)
                ->where('role', 'enseignant')
                ->first();
            if ($enseignantUser) {
                $query->where(function ($q) use ($enseignantUser, $request) {
                    $q->where('enseignant_id', $enseignantUser->id)
                      ->orWhere('enseignant_id', $request->enseignant_id);
                });
            } else {
                $query->where('enseignant_id', $request->enseignant_id);
            }
        }

        // Récupérer les leçons
        $lessons = $query->get();

        // Pré-charger les enseignants (par id ET par klassci_id pour gérer les deux cas)
        $enseignantIds = $lessons->pluck('enseignant_id')->unique()->filter()->toArray();
        $enseignants = \App\Models\User::where('role', 'enseignant')
            ->where(function ($q) use ($enseignantIds) {
                $q->whereIn('id', $enseignantIds)
                  ->orWhereIn('klassci_id', $enseignantIds);
            })
            ->get()
            ->keyBy(function ($u) {
                return $u->id;
            });

        // Index par klassci_id aussi
        $enseignantsByKlassci = $enseignants->keyBy('klassci_id');

        // Transformer pour avoir un format cohérent
        $coursesData = $lessons->map(function ($lesson) use ($user, $enseignants, $enseignantsByKlassci) {
            $progress = $lesson->progressForUser($user->id);

            // Résoudre l'enseignant (essayer par id puis par klassci_id)
            $enseignant = $enseignants->get($lesson->enseignant_id)
                ?? $enseignantsByKlassci->get($lesson->enseignant_id);

            return [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'description' => $lesson->description,
                'type' => $lesson->type,
                'duree_estimee' => $lesson->duree_estimee_minutes,
                'niveau_difficulte' => $lesson->niveau_difficulte,
                'enseignant' => $enseignant ? [
                    'id' => $enseignant->id,
                    'klassci_id' => $enseignant->klassci_id,
                    'name' => $enseignant->name,
                ] : null,
                'matiere' => $lesson->matiere ? [
                    'id' => $lesson->matiere->id,
                    'name' => $lesson->matiere->name ?? $lesson->matiere->libelle ?? 'Matière',
                ] : null,
                'classe' => $lesson->classe ? [
                    'id' => $lesson->classe->id,
                    'name' => $lesson->classe->name ?? $lesson->classe->nom ?? 'Classe',
                ] : null,
                'progress' => $progress ? [
                    'percentage' => $progress->progress_percentage,
                    'status' => $progress->status,
                    'completed_at' => $progress->completed_at,
                ] : null,
                'published_at' => $lesson->published_at,
                'created_at' => $lesson->created_at,
            ];
        });

        // Récupérer les filtres disponibles
        $uniqueEnseignants = collect();
        foreach ($lessons as $lesson) {
            $ens = $enseignants->get($lesson->enseignant_id) ?? $enseignantsByKlassci->get($lesson->enseignant_id);
            if ($ens && !$uniqueEnseignants->contains('id', $ens->id)) {
                $uniqueEnseignants->push($ens);
            }
        }

        $uniqueMatieres = $lessons->pluck('matiere')->filter()->unique('id')->values();

        return response()->json([
            'success' => true,
            'data' => $coursesData,
            'filters' => [
                'matieres' => $uniqueMatieres->map(fn($m) => ['id' => $m->id, 'name' => $m->name ?? $m->libelle ?? 'Matière']),
                'enseignants' => $uniqueEnseignants->map(fn($e) => ['id' => $e->klassci_id, 'name' => $e->name]),
            ],
            'total' => $coursesData->count(),
        ]);
    }

    /**
     * GET /api/lessons/{id}
     * Détails d'un cours
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->authenticatedUser($request);
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
    public function store(StoreLessonRequest $request): JsonResponse
    {
        // Validation + authorization handled by StoreLessonRequest
        $data = $request->validated();
        $data['enseignant_id'] = $this->authenticatedUser($request)->id;

        // Résoudre matiere_id : le frontend peut envoyer un KLASSCI ID
        if (isset($data['matiere_id'])) {
            $matiere = \App\Models\Matiere::find($data['matiere_id']);
            if (!$matiere) {
                // Chercher par klassci_id
                $matiere = \App\Models\Matiere::where('klassci_id', $data['matiere_id'])->first();
                if ($matiere) {
                    $data['matiere_id'] = $matiere->id;
                }
            }
        }

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
    public function update(UpdateLessonRequest $request, int $id): JsonResponse
    {
        $lesson = Lesson::findOrFail($id);
        $data = $request->validated();

        // Handle status transitions and published_at timestamp
        if (isset($data['status'])) {
            if ($data['status'] === 'published' && !$lesson->published_at) {
                $data['published_at'] = now();
            } elseif (in_array($data['status'], ['draft', 'archived'])) {
                $data['published_at'] = null;
            }
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
    public function destroy(DeleteLessonRequest $request, int $id): JsonResponse
    {
        $lesson = Lesson::findOrFail($id);
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
    public function publish(PublishLessonRequest $request, int $id): JsonResponse
    {
        $lesson = Lesson::findOrFail($id);
        $wasUnpublished = $lesson->status === 'draft';
        $lesson->publish();

        // Créer des notifications pour les étudiants concernés si le cours vient d'être publié
        if ($wasUnpublished && $lesson->matiere_id) {
            // Récupérer tous les étudiants de la matière via KLASSCI
            try {
                $klassciService = app(\App\Services\KlassciProxyService::class);
                $matiereData = $klassciService->get("/matieres/{$lesson->matiere_id}");

                if (isset($matiereData['data']['classe_ids']) && is_array($matiereData['data']['classe_ids'])) {
                    $studentIds = [];
                    foreach ($matiereData['data']['classe_ids'] as $classeId) {
                        $classeData = $klassciService->get("/classes/{$classeId}");
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
    public function unpublish(UnpublishLessonRequest $request, int $id): JsonResponse
    {
        $lesson = Lesson::findOrFail($id);
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

        $user = $this->authenticatedUser($request);

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

        $user = $this->authenticatedUser($request);

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

        $user = $this->authenticatedUser($request);

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

        $user = $this->authenticatedUser($request);

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
