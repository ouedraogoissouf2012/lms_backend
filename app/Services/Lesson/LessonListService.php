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
        private readonly MyCoursesPresenter $myCoursesPresenter,
    ) {
    }

    /**
     * Liste paginée des cours (filtres optionnels + restriction étudiant
     * aux cours publiés + enrichissement progression).
     *
     * Les filtres sont passés via la `Request` (déjà validée côté caller).
     *
     * @return LengthAwarePaginator<int, Lesson>
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
            $query->forMatiere($request->integer('matiere_id'));
        }

        if ($request->has('classe_id')) {
            $query->forClasse($request->integer('classe_id'));
        }

        if ($request->has('enseignant_id')) {
            $query->byTeacher($request->integer('enseignant_id'));
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
        $perPage = $request->integer('per_page', 15);
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
     * Paginé (#483) : `data` reste un tableau plat, `meta` porte la pagination.
     *
     * @return array{
     *     courses: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *     filters: array{matieres: \Illuminate\Support\Collection<int, array{id: int, name: string}>, enseignants: \Illuminate\Support\Collection<int, array{id: int|null, name: string}>},
     *     total: int,
     *     meta: array{current_page: int, last_page: int, per_page: int, total: int},
     * }
     */
    public function myCourses(Request $request, User $user): array
    {
        $query = $this->buildMyCoursesQuery($request, $user);

        $perPage = $request->integer('per_page', 15);
        $page = (clone $query)->paginate($perPage);

        // Filtres exhaustifs (#483 REQ-5) : dérivés de TOUTE la sélection
        // filtrée, pas de la seule page — sinon les menus déroulants seraient
        // incomplets au-delà de la 1re page.
        $allFiltered = (clone $query)->get();

        return $this->myCoursesPresenter->present($page, $allFiltered, $user);
    }

    /**
     * Construit la requête « Mes cours » : cours publiés + tenant + restriction
     * classe étudiant (#482) + filtres optionnels matiere/enseignant. Aucune
     * exécution (retourne un Builder), pour permettre pagination ET filtres.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Lesson>
     */
    private function buildMyCoursesQuery(Request $request, User $user): \Illuminate\Database\Eloquent\Builder
    {
        $query = Lesson::with(['matiere', 'classe'])->published()->ordered();

        // Defense en profondeur (fix E2E #211 flow 5) — cf. list().
        if ($user->institution_id !== null) {
            $query->where('institution_id', $user->institution_id);
        }

        // Étudiant : restreindre à SA classe (#482). classe_id NULL et autres
        // classes exclues.
        if ($user->isStudent()) {
            $query->whereIn('classe_id', $this->classeResolver->localClasseIdsFor($user));
        }

        if ($request->has('matiere_id')) {
            $query->forMatiere($request->integer('matiere_id'));
        }

        if ($request->has('enseignant_id')) {
            $this->applyEnseignantFilter($query, (int) $request->integer('enseignant_id'));
        }

        return $query;
    }

    /**
     * Filtre enseignant tolérant : le frontend envoie un klassci_id ; on
     * accepte aussi l'id local correspondant.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Lesson>  $query
     */
    private function applyEnseignantFilter(\Illuminate\Database\Eloquent\Builder $query, int $enseignantId): void
    {
        $enseignantUser = User::where('klassci_id', $enseignantId)
            ->where('role', 'enseignant')
            ->first();

        if ($enseignantUser) {
            $query->where(function ($q) use ($enseignantUser, $enseignantId) {
                $q->where('enseignant_id', $enseignantUser->id)
                    ->orWhere('enseignant_id', $enseignantId);
            });

            return;
        }

        $query->where('enseignant_id', $enseignantId);
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
