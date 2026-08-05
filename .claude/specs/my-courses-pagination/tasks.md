# Tasks — Pagination + validation de `/lessons/my-courses` (#483)

Ordre TDD : RED → GREEN → non-régression → vérif.

## 1. Tests (RED)

- [ ] **1.1** Créer `tests/Feature/Lesson/MyCoursesPaginationTest.php`.
  - **1.1a** `test_my_courses_is_paginated_and_data_stays_flat` : étudiant
    inscrit à sa classe (pont #482), 20 cours publiés de SA classe,
    `GET /lessons/my-courses?per_page=5` → `data` = **5 éléments plats** (chacun
    avec `id`,`title`,`enseignant`,`matiere`…), `meta.total=20`,
    `meta.last_page=4`, `meta.per_page=5`. _(REQ-2/3/4, AC2/3/4.)_
  - **1.1b** `test_my_courses_rejects_oversized_per_page` : `per_page=1000` →
    **422** (anti-DOS via MyCoursesRequest). _(REQ-1, AC1.)_
  - **1.1c** `test_my_courses_default_page_size` : sans `per_page` → 1re page,
    ≤15 éléments, pas d'erreur. _(REQ-7.)_
  - **1.1d** `test_my_courses_pagination_preserves_classe_restriction` : cours
    d'une AUTRE classe absent même en paginant (régression #482). _(REQ-6, AC5.)_
  - **1.1e** `test_my_courses_filters_cover_all_pages` : avec 2 matières réparties
    sur 2 pages, `filters.matieres` contient les **2** (pas seulement celles de
    la page 1). _(REQ-5.)_
  - **Lancer → RED** (aujourd'hui non paginé, MyCoursesRequest non branché).

## 2. Implémentation (GREEN)

- [ ] **2.1** Compléter `app/Http/Requests/MyCoursesRequest.php` : règles
  `matiere_id`/`enseignant_id` (`sometimes|integer|min:1`) en plus de
  `page`/`per_page`. _(REQ-1.)_
- [ ] **2.2** `LessonCrudController::myCourses()` : type-hint `MyCoursesRequest`
  (au lieu de `Request`) + ajouter `meta` dans la réponse JSON. _(REQ-1/4.)_
- [ ] **2.3** Créer `app/Services/Lesson/MyCoursesPresenter.php` :
  `present(paginator, baseForFilters, User): array` — items plats + `filters`
  (dictionnaires sur base complète) + `total` + `meta`. _(REQ-3/4/5.)_
- [ ] **2.4** `LessonListService::myCourses()` : extraire `buildMyCoursesQuery()`
  (privée, filtres + classe #482), paginer, déléguer au presenter. Orchestrateur
  **≤40 lignes**. Injecter `MyCoursesPresenter`. _(REQ-2/6, §1.1.)_
- [ ] **2.5** Lancer 1.1 → **GREEN**.

## 3. Non-régression

- [ ] **3.1** `php artisan test tests/Feature/Lesson/
  tests/Feature/E2E/TeacherLessonPublicationFlowTest.php` → 100 % (le contrat
  `data` plat doit rester valide pour les tests existants de my-courses).
- [ ] **3.2** `php artisan test` global (via CI si trop long en local).

## 4. Vérification

- [ ] **4.1** PHPStan level 9 sur les 4 fichiers touchés → 0 erreur.
- [ ] **4.2** Garde tailles : `myCourses()` **≤40 l.**, `MyCoursesPresenter`
  ≤300 / méthodes ≤40, `LessonListService` ≤300.
- [ ] **4.3** Vérifier le contrat exact renvoyé (clés `success`,`data`,`filters`,
  `total`,`meta`) — aucune clé existante retirée/renommée.

## 5. Clôture

- [ ] **5.1** Après merge PR : fermer #483 explicitement + récap → **lot lessons
  entièrement clos** (#481/#482/#483).

## Traçabilité exigences → tâches

| Exigence | Tâche(s) |
|---|---|
| REQ-1 (validation branchée) | 1.1b, 2.1, 2.2 |
| REQ-2 (pagination) | 1.1a, 2.4 |
| REQ-3 (data plat) | 1.1a, 2.3, 4.3 |
| REQ-4 (meta additif) | 1.1a, 2.2, 2.3 |
| REQ-5 (filtres complets) | 1.1e, 2.3 |
| REQ-6 (classe #482) | 1.1d, 2.4 |
| REQ-7 (défaut) | 1.1c, 2.4 |
