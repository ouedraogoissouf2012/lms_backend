<?php

declare(strict_types=1);

namespace App\Services\Lesson;

use App\Models\Lesson;
use App\Models\LessonProgress;
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
    public function __construct(private readonly LessonProgressService $progressService) {}

    /**
     * @param  LengthAwarePaginator<int, Lesson>  $page  Cours de la page courante.
     * @param  Collection<int, \stdClass>  $selection  Toute la sélection filtrée (hors pagination),
     *                                                réduite à `matiere_id` et `enseignant_id`, dans son
     *                                                ordre — sert à bâtir des filtres exhaustifs (#940).
     * @return array{
     *     courses: Collection<int, array<string, mixed>>,
     *     filters: array{matieres: Collection<int, array{id: int, name: string}>, enseignants: Collection<int, array{id: int, name: string}>},
     *     total: int,
     *     meta: array{current_page: int, last_page: int, per_page: int, total: int},
     * }
     */
    public function present(LengthAwarePaginator $page, Collection $selection, User $user): array
    {
        /** @var Collection<int, Lesson> $items */
        $items = $page->getCollection();
        $enseignantIds = self::idsDans($selection, 'enseignant_id');
        $enseignants = $this->preloadEnseignants($enseignantIds);
        // La progression de la page en UNE requête, et non une par cours (#940).
        $progressions = $this->progressService->progressionsForUser(
            $items->map(static fn (Lesson $lesson): int => (int) $lesson->id)->values()->all(),
            $user->id,
        );

        $courses = $items->map(
            fn (Lesson $lesson): array => $this->mapCourse($lesson, $progressions->get($lesson->id), $enseignants)
        );

        return [
            'courses' => $courses,
            'filters' => $this->buildFilters(self::idsDans($selection, 'matiere_id'), $enseignantIds, $enseignants),
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
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    private function preloadEnseignants(array $ids): Collection
    {
        // `lessons.enseignant_id` est LOCAL par contrat : sa migration le dit
        // (`comment('users.id (LOCAL)')`, 2025_10_14_160000:35) et son unique
        // ecrivain ecrit `$author->id` (LessonCrudOperationsService:44).
        //
        // La precharge resolvait pourtant `whereIn('id')->orWhereIn('klassci_id')`,
        // puis un SECOND dictionnaire indexe sur `klassci_id` servait de repli.
        // Les deux espaces se croisant -- neuf collisions mesurees en production
        // le 19/09/2026 -- ce repli attribuait la lecon a QUELQU UN D AUTRE (#869).
        //
        // Un seul espace, donc un seul dictionnaire : le repli disparait avec la
        // question qu il arbitrait.
        //
        // Aucun filtre de role : l auteur est l auteur. `role = 'enseignant'`
        // excluait le coordinateur, que la route de creation autorise, et ratait
        // l alias `'teacher'` (Role.php:64). C est cette exclusion qui faisait
        // tomber la recherche locale dans le repli miroir.
        //
        // `withTrashed()` : le nom de l auteur doit survivre a la suppression de
        // son compte (#566).
        /** @var Collection<int, User> $enseignants */
        $enseignants = User::withTrashed()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(fn (User $u) => $u->id);

        return $enseignants;
    }

    /**
     * @param  Collection<int, User>  $enseignants
     * @return array<string, mixed>
     */
    private function mapCourse(Lesson $lesson, ?LessonProgress $progress, Collection $enseignants): array
    {
        $enseignant = $enseignants->get($lesson->enseignant_id);

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
            // `matieres` n'a pas de colonne `name` : seulement `libelle`, NOT NULL.
            // L'ancien repli `name ?? libelle ?? 'Matière'` lisait une colonne
            // inexistante et gardait une constante inatteignable (#962).
            'matiere' => $lesson->matiere ? [
                'id' => $lesson->matiere->id,
                'name' => $lesson->matiere->libelle,
            ] : null,
            // `classes` n'a ni `name` ni `nom` : seulement `libelle`, NOT NULL.
            // L'ancien repli `name ?? nom ?? 'Classe'` rendait toujours la
            // constante (#939). La clé `name` reste, la forme ne change pas.
            'classe' => $lesson->classe ? [
                'id' => $lesson->classe->id,
                'name' => $lesson->classe->libelle,
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
     * Dictionnaires de filtres dérivés de TOUTE la sélection filtrée (#483 REQ-5),
     * dans l'ordre d'apparition, sans hydrater une seule leçon (#940).
     *
     * @param  list<int>  $matiereIds
     * @param  list<int>  $enseignantIds
     * @param  Collection<int, User>  $enseignants
     * @return array{matieres: Collection<int, array{id: int, name: string}>, enseignants: Collection<int, array{id: int, name: string}>}
     */
    private function buildFilters(array $matiereIds, array $enseignantIds, Collection $enseignants): array
    {
        $matieres = Matiere::query()->whereIn('id', $matiereIds)->get()->keyBy('id');

        return [
            'matieres' => collect($matiereIds)
                ->map(static fn (int $id): ?Matiere => $matieres->get($id))
                ->filter()
                ->map(static fn (Matiere $m): array => ['id' => $m->id, 'name' => $m->libelle])
                ->values(),
            'enseignants' => collect($enseignantIds)
                ->map(static fn (int $id): ?User => $enseignants->get($id))
                ->filter()
                ->map(static fn (User $e): array => ['id' => $e->id, 'name' => (string) $e->name])
                ->values(),
        ];
    }

    /**
     * Les identifiants distincts et non nuls d'une colonne, dans l'ordre
     * d'apparition de la sélection.
     *
     * @param  Collection<int, \stdClass>  $selection
     * @return list<int>
     */
    private static function idsDans(Collection $selection, string $colonne): array
    {
        $ids = [];
        foreach ($selection as $ligne) {
            $id = $ligne->{$colonne} ?? null;
            if (is_numeric($id) && ! in_array((int) $id, $ids, true)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
