# Design — Pagination + validation de `/lessons/my-courses` (#483)

## 1. Constat : `myCourses()` fait 113 lignes (111-224)

La méthode mélange **4 responsabilités** :
1. construction requête + filtres (classe #482, matiere, enseignant) ;
2. préchargement des enseignants (par id ET klassci_id) ;
3. transformation applatie (map → dictionnaire de cours) ;
4. extraction des dictionnaires de filtres (matières / enseignants distincts).

Ajouter la pagination **sans découper** aggraverait une méthode déjà 3× hors
limite (§1.1 ≤40, « Aucune exception »). #483 étant une issue de **dette
technique**, la découpe est dans le périmètre.

## 2. Découpe en collaborateur dédié

Nouveau service **`MyCoursesPresenter`** (pur, DI) qui porte la transformation
et l'extraction des filtres — `LessonListService::myCourses()` orchestre.

```
LessonListService::myCourses(MyCoursesRequest, User): array   (orchestrateur ≤40 l.)
  1. buildMyCoursesQuery(request, user)      → Builder (filtres + classe #482)
  2. $page = $query->paginate($perPage)      → LengthAwarePaginator
  3. $presenter->present($page, $filterBase, $user)  → { data, filters, total, meta }
```

- `buildMyCoursesQuery()` : méthode privée qui isole requête + filtres
  (réutilise la logique existante, y compris le filtre classe #482).
- `MyCoursesPresenter::present()` : reçoit la **page** (items à applatir) + la
  **base filtrée non paginée** (pour des filtres complets, cf. §4) + le user
  (progression) ; retourne le tableau final.

## 3. Pagination compatible (REQ-2/3/4) — contrat `data` plat préservé

```php
$perPage = (int) $request->integer('per_page', 15);
$page = $this->buildMyCoursesQuery($request, $user)->paginate($perPage);

// data reste un TABLEAU PLAT (items de la page applatis), PAS le paginator brut.
return [
    'courses' => $flatItems,          // ← response.data côté frontend (inchangé)
    'filters' => [...],
    'total'   => $page->total(),      // total réel (toutes pages), pas count(page)
    'meta'    => [
        'current_page' => $page->currentPage(),
        'last_page'    => $page->lastPage(),
        'per_page'     => $page->perPage(),
        'total'        => $page->total(),
    ],
];
```

Le contrôleur continue d'émettre `{ success, data, filters, total, meta }` :
`data` = `$result['courses']` (tableau plat) → `response.data` du frontend
**inchangé** (REQ-3). `meta` est **additif** (REQ-4).

> Note contrat : `total` passe de `count(page)` à `$page->total()` (total réel).
> C'est une **correction** de sens (le champ s'appelle « total »), pas une
> rupture de forme. Le frontend ne lit pas `total` (vérifié) → sans impact.

## 4. Filtres complets malgré la pagination (REQ-5, décision)

Aujourd'hui `filters` (menus déroulants matières/enseignants) est dérivé de
**tous** les cours. Le frontend les charge **une fois** pour peupler les selects
(`useStudentCourses.js:41`). Les limiter à la page courante rendrait les menus
**incomplets**.

**Décision** : dériver `filters` de la **sélection filtrée complète** (même
requête, sans `paginate` — un `->get()` des colonnes utiles OU réutiliser
`$page->total()` n'y suffit pas). Concrètement :

```php
$base = $this->buildMyCoursesQuery($request, $user);        // Builder
$page = (clone $base)->paginate($perPage);                  // items page
$allForFilters = (clone $base)->with(['matiere'])->get();   // pour les dictionnaires
```

Coût : une requête supplémentaire bornée par les filtres (classe étudiante →
volume réduit). Acceptable et **cohérent avec le comportement actuel** (filtres
exhaustifs). Documenté comme choix explicite.

## 5. `MyCoursesRequest` complété + branché (REQ-1)

```php
public function rules(): array
{
    return [
        'page'          => ['sometimes', 'integer', 'min:1'],
        'per_page'      => ['sometimes', 'integer', 'min:1', 'max:100'], // anti-DOS
        'matiere_id'    => ['sometimes', 'integer', 'min:1'],
        'enseignant_id' => ['sometimes', 'integer', 'min:1'],
    ];
}
```

Contrôleur : `myCourses(MyCoursesRequest $request)` (au lieu de `Request`).
Le service reçoit ce `MyCoursesRequest` (compatible `Request`).

## 6. Décisions & justifications

| Décision | Pourquoi |
|---|---|
| `MyCoursesPresenter` collaborateur | Découpe `myCourses()` sous 40 l. (§1.1) ; transformation testable en isolation. |
| `data` = items plats (pas paginator brut) | REQ-3 : `response.data` frontend inchangé (Q15 n°1 évité). |
| `meta` additif | REQ-4 : pagination exposée sans casser `data`/`filters`. |
| `filters` sur base complète, pas la page | Menus déroulants exhaustifs (REQ-5), cohérent avec l'actuel. |
| `per_page` borné 1–100 | Anti-DOS (Q15 n°2). |
| classe #482 dans `buildMyCoursesQuery` | Pagination APRÈS le filtre classe (REQ-6). |
| Compléter `MyCoursesRequest` (pas `FilterLessonsRequest`) | `myCourses` n'utilise ni `status` ni `type` ni `classe_id` client ; règles ciblées. |

## 7. Fichiers touchés

| Fichier | Nature |
|---|---|
| `app/Http/Requests/MyCoursesRequest.php` | +règles `matiere_id`/`enseignant_id`. |
| `app/Http/Controllers/API/Lesson/LessonCrudController.php` | type-hint `MyCoursesRequest` + `meta` dans la réponse. |
| `app/Services/Lesson/LessonListService.php` | `myCourses()` découpée (orchestrateur + `buildMyCoursesQuery`), pagination. |
| `app/Services/Lesson/MyCoursesPresenter.php` (NEW) | Transformation applatie + dictionnaires de filtres. |
| `tests/Feature/Lesson/MyCoursesPaginationTest.php` (NEW) | pagination, `data` plat, `meta`, per_page borné, classe #482 préservée. |
