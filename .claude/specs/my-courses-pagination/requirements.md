# Requirements — Pagination + validation de `/lessons/my-courses` (#483)

## Contexte & preuves

Deux incohérences sur `GET /lessons/my-courses` :

1. **`MyCoursesRequest` mort** (`app/Http/Requests/MyCoursesRequest.php`) : le
   contrôleur `LessonCrudController::myCourses()` (`:56`) prend un
   `Illuminate\Http\Request` **générique** → la validation (`page`, `per_page`)
   n'est jamais appliquée. De plus, `MyCoursesRequest` ne valide pas
   `matiere_id`/`enseignant_id` que `myCourses()` **utilise pourtant**
   (`LessonListService.php:120,124`).
2. **Pas de pagination** : `myCourses()` fait `->get()`
   (`LessonListService.php:140`) → renvoie toute la collection. Non scalable.

## Contrainte frontend (vérifiée)

`useStudentCourses.js:37-41` lit **`response.data`** (tableau **plat** de cours)
et **`response.filters`**. **Aucune** gestion de pagination côté client (ni
`page`, ni `data.data`, ni `meta`). ⇒ Le contrat `data = tableau plat` **ne doit
pas changer**.

## Décision

Brancher `MyCoursesRequest` (complété) + paginer côté service, **en préservant**
`data` = tableau plat. Exposer la pagination via une clé `meta` **additive**.

## Portée

- **IN** : compléter `MyCoursesRequest` (page/per_page/matiere_id/enseignant_id),
  le brancher sur `myCourses()`, paginer la requête, exposer `meta`.
- **OUT** : `list()`/`index()` (déjà paginé + `FilterLessonsRequest`) ; le
  filtrage classe (#482) ; l'invariant published_at (#481).

## Exigences (EARS)

**REQ-1 — Validation branchée**
WHEN `myCourses()` est appelé, THE SYSTEM SHALL valider les paramètres via
`MyCoursesRequest` : `page` (int ≥1), `per_page` (int 1–100, anti-DOS),
`matiere_id` (int positif), `enseignant_id` (int positif) — tous `sometimes`.

**REQ-2 — Pagination effective**
THE SYSTEM SHALL paginer la requête (`->paginate($perPage)` avec `per_page`
par défaut 15), au lieu de `->get()`.

**REQ-3 — Contrat `data` inchangé (non cassant)**
THE SYSTEM SHALL continuer d'exposer `data` comme un **tableau plat** de cours
(la même forme applatie qu'aujourd'hui), pour que `response.data` du frontend
reste valide. La structure paginator brute (`data.data`) NE DOIT PAS remplacer
`data`.

**REQ-4 — Métadonnées de pagination additives**
THE SYSTEM SHALL exposer les infos de pagination sous une clé `meta`
(`current_page`, `last_page`, `per_page`, `total`) **en plus** de `data`,
`filters`, `total` existants — sans retirer ni renommer les clés actuelles.

**REQ-5 — Filtres cohérents avec la page**
THE SYSTEM SHALL construire `filters` (dictionnaires matières/enseignants) à
partir des cours **de la page courante** (cohérent avec `data`) — comportement
identique à l'actuel qui les dérive de la collection renvoyée.

**REQ-6 — Restriction classe préservée (#482)**
THE SYSTEM SHALL conserver la restriction classe étudiant introduite en #482
(la pagination s'applique APRÈS le filtre classe).

**REQ-7 — Défaut sans paramètres**
WHERE aucun `page`/`per_page` n'est fourni, THE SYSTEM SHALL se comporter comme
aujourd'hui du point de vue client (première page, 15 éléments) sans erreur.

## Critères d'acceptation

1. `MyCoursesRequest` est le type-hint de `myCourses()` (plus de `Request`
   générique) ; `per_page=1000` est rejeté (422, anti-DOS).
2. `response.data` reste un **tableau plat** ; le frontend actuel fonctionne
   sans modification.
3. `response.meta.total` et `response.meta.last_page` reflètent la pagination.
4. Avec 20 cours et `per_page=5`, `data` contient 5 éléments, `meta.total=20`,
   `meta.last_page=4`.
5. La restriction classe #482 reste effective (un étudiant ne voit que sa
   classe, paginée).
6. `php artisan test` = 100 % ; PHPStan level 9 vert ; garde tailles OK.

## Q15 — Critères d'invalidation

- ❌ `data` devient la structure paginator brute (`data.data`) → frontend cassé.
- ❌ `per_page` non borné → DOS (per_page=1000000).
- ❌ La pagination court-circuite le filtre classe #482 (régression sécurité).
- ❌ `filters`/`total` existants renommés ou retirés (rupture de contrat).
- ❌ `MyCoursesRequest` toujours non branché (issue non résolue).
- ❌ `myCourses()` dépasse 40 lignes après ajout (à surveiller ; extraire si
  besoin).
