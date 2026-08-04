<?php

declare(strict_types=1);

namespace App\Services\Lesson;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * Read-side orchestration des leçons (split-15/lesson-crud).
 *
 * Extrait de `LessonCrudController` : centralise les 3 endpoints "lecture"
 * (`index`, `myCourses`, `show`) — filtres, scopes, enrichissement
 * (progression / statistiques enseignant / résolution enseignant cross-source).
 *
 * ## DI strict (§1.6 D)
 *
 * Pas de Facade ni `app()` — toute la logique se base sur les modèles et
 * relations Eloquent passés en argument.
 *
 * ## Comportement
 *
 * Aucun changement runtime : déplacement verbatim depuis le god-controller.
 *
 * @see app/Http/Controllers/API/Lesson/LessonCrudController.php
 */
final class LessonListService
{
    public function __construct(
        private readonly LessonProgressService $progressService,
        private readonly StudentClasseResolver $classeResolver,
    ) {
    }

    /**
     * Liste paginée des cours (filtres optionnels + restriction étudiant
     * aux cours publiés + enrichissement progression).
     *
     * Les filtres sont passés via la `Request` (déjà validée côté caller).
     *
     * @return LengthAwarePaginator<Lesson>
     */
    public function list(Request $request, User $user): LengthAwarePaginator
    {
        $query = Lesson::with(['matiere', 'enseignant', 'classe']);

        // Defense en profondeur (fix E2E #211 flow 5) : le global scope
        // BelongsToInstitution est no-op si le tenant n'est pas resolu —
        // filtre tenant EXPLICITE depuis le user authentifie.
        // institution_id null = supradmin cross-tenant by design.
        if ($user->institution_id !== null) {
            $query->where('institution_id', $user->institution_id);
        }

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

        // Étudiants : uniquement les cours publiés ET de LEUR classe (#482 —
        // isolation inter-classes, résolue via le pont KLASSCI→local).
        if ($user->isStudent()) {
            $query->published()
                ->whereIn('classe_id', $this->classeResolver->localClasseIdsFor($user));
        } elseif ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Pagination — DOS-protected by FilterLessonsRequest (per_page: 1-100)
        $perPage = $request->get('per_page', 15);
        $lessons = $query->ordered()->paginate($perPage);

        // Ajouter la progression pour chaque cours (si étudiant)
        if ($user->isStudent()) {
            $lessons->getCollection()->transform(function ($lesson) use ($user) {
                $lesson->user_progress = $this->progressService->progressForUser($lesson, $user->id);
                return $lesson;
            });
        }

        return $lessons;
    }

    /**
     * Liste des cours pour la vue "Mes cours" d'un étudiant — format applati
     * avec enseignant / matière / classe / progression + dictionnaires de
     * filtres (matières + enseignants distincts).
     *
     * Gère la résolution enseignant cross-source (id local OU `klassci_id`).
     *
     * @return array{
     *     courses: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *     filters: array{matieres: \Illuminate\Support\Collection<int, array<string, mixed>>, enseignants: \Illuminate\Support\Collection<int, array<string, mixed>>},
     *     total: int,
     * }
     */
    public function myCourses(Request $request, User $user): array
    {
        // Construire la requête - Tous les cours publiés
        $query = Lesson::with(['matiere', 'classe'])
            ->published()
            ->ordered();

        // Defense en profondeur (fix E2E #211 flow 5) — cf. list().
        if ($user->institution_id !== null) {
            $query->where('institution_id', $user->institution_id);
        }

        // Étudiant : restreindre à SA classe (#482). Les leçons sans classe
        // (classe_id NULL) et celles d'autres classes sont exclues.
        if ($user->isStudent()) {
            $query->whereIn('classe_id', $this->classeResolver->localClasseIdsFor($user));
        }

        // Filtres optionnels
        if ($request->has('matiere_id')) {
            $query->forMatiere($request->matiere_id);
        }

        if ($request->has('enseignant_id')) {
            // Chercher l'user par klassci_id (car le frontend envoie klassci_id)
            $enseignantUser = User::where('klassci_id', $request->enseignant_id)
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
        $enseignants = User::where('role', 'enseignant')
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
            $progress = $this->progressService->progressForUser($lesson, $user->id);

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

        return [
            'courses' => $coursesData,
            'filters' => [
                'matieres' => $uniqueMatieres->map(fn($m) => ['id' => $m->id, 'name' => $m->name ?? $m->libelle ?? 'Matière']),
                'enseignants' => $uniqueEnseignants->map(fn($e) => ['id' => $e->klassci_id, 'name' => $e->name]),
            ],
            'total' => $coursesData->count(),
        ];
    }

    /**
     * Détails d'un cours par id + enrichissement (progression user et
     * statistiques enseignant si rôle pertinent).
     *
     * @return array{lesson: Lesson}|array{error: string, status: int}
     */
    public function show(User $user, int $id): array
    {
        $lesson = Lesson::find($id);

        if (!$lesson) {
            return ['error' => 'Cours non trouvé', 'status' => 404];
        }

        // Defense en profondeur (fix E2E #211 flow 5) : acces direct par ID
        // cross-tenant -> 404 (ne pas revelér l'existence de la ressource).
        if ($user->institution_id !== null && $lesson->institution_id !== $user->institution_id) {
            return ['error' => 'Cours non trouvé', 'status' => 404];
        }

        // Vérifier les permissions
        if ($user->isStudent() && !$lesson->isPublished()) {
            return ['error' => 'Ce cours n\'est pas encore disponible', 'status' => 403];
        }

        // Charger la progression de l'utilisateur
        $lesson->user_progress = $this->progressService->progressForUser($lesson, $user->id);

        // Statistiques (pour enseignants uniquement)
        if ($user->isStaff()) {
            $lesson->statistics = [
                'students_started' => $this->progressService->studentsStartedCount($lesson),
                'students_completed' => $this->progressService->studentsCompletedCount($lesson),
                'average_completion_rate' => round($this->progressService->averageCompletionRate($lesson), 2),
            ];
        }

        return ['lesson' => $lesson];
    }
}
