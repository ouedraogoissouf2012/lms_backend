<?php

declare(strict_types=1);

namespace App\Services\Lesson;

use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Présente la vue « Mes cours » d'un étudiant : items applatis + dictionnaires
 * de filtres + métadonnées de pagination.
 *
 * ## Découpe (issue #483)
 *
 * Extrait de `LessonListService::myCourses()` (qui dépassait 100 lignes) pour
 * respecter §1.1 (méthodes ≤40 lignes). Data-mapping pur : la résolution
 * enseignant cross-source (id local OU `klassci_id`) et l'aplatissement sont
 * ici ; la construction de requête et la pagination restent dans le service.
 *
 * Le champ `data` reste un TABLEAU PLAT (contrat frontend `response.data`,
 * #483) ; `meta` est additif ; `filters` couvre TOUTE la sélection filtrée
 * (pas seulement la page) pour des menus déroulants exhaustifs.
 */
final class MyCoursesPresenter
{
    public function __construct(private readonly LessonProgressService $progressService)
    {
    }

    /**
     * @param  LengthAwarePaginator<int, Lesson>  $page  Cours de la page courante.
     * @param  Collection<int, Lesson>  $allFiltered  Toute la sélection filtrée
     *         (hors pagination) — sert à bâtir des filtres exhaustifs.
     * @return array{
     *     courses: Collection<int, array<string, mixed>>,
     *     filters: array{matieres: Collection<int, array{id: int, name: string}>, enseignants: Collection<int, array{id: int|null, name: string}>},
     *     total: int,
     *     meta: array{current_page: int, last_page: int, per_page: int, total: int},
     * }
     */
    public function present(LengthAwarePaginator $page, Collection $allFiltered, User $user): array
    {
        /** @var Collection<int, Lesson> $items */
        $items = $page->getCollection();
        $enseignants = $this->preloadEnseignants($allFiltered);
        $enseignantsByKlassci = $enseignants->keyBy('klassci_id');

        $courses = $items->map(
            fn (Lesson $lesson): array => $this->mapCourse($lesson, $user, $enseignants, $enseignantsByKlassci)
        );

        return [
            'courses' => $courses,
            'filters' => $this->buildFilters($allFiltered, $enseignants, $enseignantsByKlassci),
            'total' => $page->total(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    /**
     * Précharge les enseignants par id ET par klassci_id (le rattachement
     * `lessons.enseignant_id` peut être l'un ou l'autre selon la source).
     *
     * @param  Collection<int, Lesson>  $lessons
     * @return Collection<int, User>
     */
    private function preloadEnseignants(Collection $lessons): Collection
    {
        $ids = $lessons->pluck('enseignant_id')->unique()->filter()->all();

        /** @var Collection<int, User> $enseignants */
        $enseignants = User::where('role', 'enseignant')
            ->where(function ($query) use ($ids) {
                $query->whereIn('id', $ids)->orWhereIn('klassci_id', $ids);
            })
            ->get()
            ->keyBy(fn (User $u) => $u->id);

        return $enseignants;
    }

    /**
     * @param  Collection<int, User>  $enseignants
     * @param  Collection<int, User>  $enseignantsByKlassci
     * @return array<string, mixed>
     */
    private function mapCourse(Lesson $lesson, User $user, Collection $enseignants, Collection $enseignantsByKlassci): array
    {
        $progress = $this->progressService->progressForUser($lesson, $user->id);
        $enseignant = $enseignants->get($lesson->enseignant_id) ?? $enseignantsByKlassci->get($lesson->enseignant_id);

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
    }

    /**
     * Dictionnaires de filtres dérivés de TOUTE la sélection filtrée (#483 REQ-5).
     *
     * @param  Collection<int, Lesson>  $allFiltered
     * @param  Collection<int, User>  $enseignants
     * @param  Collection<int, User>  $enseignantsByKlassci
     * @return array{matieres: Collection<int, array{id: int, name: string}>, enseignants: Collection<int, array{id: int|null, name: string}>}
     */
    private function buildFilters(Collection $allFiltered, Collection $enseignants, Collection $enseignantsByKlassci): array
    {
        /** @var Collection<int, User> $uniqueEnseignants */
        $uniqueEnseignants = collect();
        foreach ($allFiltered as $lesson) {
            $ens = $enseignants->get($lesson->enseignant_id) ?? $enseignantsByKlassci->get($lesson->enseignant_id);
            if ($ens && ! $uniqueEnseignants->contains('id', $ens->id)) {
                $uniqueEnseignants->push($ens);
            }
        }

        /** @var Collection<int, Matiere> $uniqueMatieres */
        $uniqueMatieres = $allFiltered->pluck('matiere')->filter()->unique('id')->values();

        return [
            'matieres' => $uniqueMatieres->map(fn (Matiere $m): array => [
                'id' => $m->id,
                'name' => (string) ($m->name ?? $m->libelle ?? 'Matière'),
            ]),
            'enseignants' => $uniqueEnseignants->map(fn (User $e): array => [
                'id' => $e->klassci_id,
                'name' => (string) $e->name,
            ]),
        ];
    }
}
