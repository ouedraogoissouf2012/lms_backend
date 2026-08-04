# Tasks — Restreindre les leçons d'un étudiant à sa classe (#482)

Ordre TDD : RED → GREEN → non-régression → vérif.

## 1. Test d'isolation (RED)

- [ ] **1.1** Créer `tests/Feature/Lesson/MyCoursesClasseScopingTest.php`.
  - **1.1a** `test_student_sees_only_lessons_of_their_classe` : étudiant lié
    (via `UserClass.klassci_classe_id`) à la classe A (locale id résolu par
    `classes.klassci_id`) ; 1 leçon publiée classe A + 1 leçon publiée classe B.
    `GET /api/lessons/my-courses` → **seule** la leçon A dans `data`. _(REQ-1, AC1.)_
  - **1.1b** `test_student_does_not_see_lessons_without_classe` : leçon publiée
    `classe_id = NULL` → **absente** du résultat étudiant. _(REQ-3, AC2.)_
  - **1.1c** `test_student_without_classe_gets_empty_list` : étudiant sans
    `UserClass` → `data` vide, statut 200, pas d'exception. _(REQ-4, AC3.)_
  - **1.1d** `test_bridge_klassci_to_local_resolves_correct_lessons` : vérifie
    explicitement le pont (leçon rattachée à `classes.id` dont `klassci_id`
    correspond à la `UserClass` → visible). _(REQ, AC5.)_
  - **Lancer → RED** (aujourd'hui l'étudiant voit A + B + NULL).

- [ ] **1.2** Créer `tests/Feature/Lesson/LessonIndexClasseScopingTest.php` :
  même isolation A/B pour `GET /api/lessons` (endpoint `list()`/`index`).
  _(REQ-2.)_

- [ ] **1.3** Test de non-régression rôles :
  `test_teacher_is_not_restricted_by_classe` — un enseignant/coordinateur voit
  les leçons indépendamment de la classe (comportement inchangé). _(REQ-5, AC4.)_

## 2. Implémentation (GREEN)

- [ ] **2.1** Créer `app/Services/Lesson/StudentClasseResolver.php` :
  `localClasseIdsFor(User): list<int>` — 2 `pluck` bornés (UserClass tenant-scopé
  → Classe via `klassci_id`), retour `[]` si aucune classe. _(design §2, REQ-4/6/7.)_
- [ ] **2.2** `LessonListService` : ajouter `StudentClasseResolver` en dépendance
  constructeur.
- [ ] **2.3** `myCourses()` : après le filtre tenant, `if ($user->isStudent())`
  → `whereIn('classe_id', $this->classeResolver->localClasseIdsFor($user))`.
  _(REQ-1.)_
- [ ] **2.4** `list()` : dans la branche `isStudent()` existante, ajouter le même
  `whereIn` à côté de `->published()`. _(REQ-2, REQ-5 : ne touche pas les autres
  rôles.)_
- [ ] **2.5** Lancer 1.1 / 1.2 / 1.3 → **GREEN**.

## 3. Non-régression suite Lesson

- [ ] **3.1** `php artisan test tests/Feature/Lesson/ tests/Unit/…Lesson…` →
  ajuster les tests existants de `myCourses`/`list` avec étudiant qui, sans
  `UserClass`, attendaient des leçons : leur fournir la classe pontée (le nouveau
  comportement vide est **correct**). Documenter chaque ajustement.
- [ ] **3.2** Vérifier qu'aucun test hors-Lesson ne dépend de l'ancien
  comportement « étudiant voit tout » (`php artisan test` global en fin).

## 4. Vérification

- [ ] **4.1** PHPStan level 9 sur les 2 fichiers touchés → 0 erreur.
- [ ] **4.2** Garde tailles : `StudentClasseResolver` ≤300, méthodes ≤40 ;
    `LessonListService` toujours ≤300.
- [ ] **4.3** Contrôle N+1 : la résolution classe = 2 requêtes constantes
    (indépendantes du nombre de leçons) — vérifier via `DB::enableQueryLog()` si
    pertinent, sinon revue.

## 5. Clôture

- [ ] **5.1** Après merge PR : fermer #482 explicitement + récap (périmètre
  élargi à `list()` signalé).

## Traçabilité exigences → tâches

| Exigence | Tâche(s) |
|---|---|
| REQ-1 (myCourses) | 1.1a, 2.3, 2.5 |
| REQ-2 (list) | 1.2, 2.4, 2.5 |
| REQ-3 (NULL exclu) | 1.1b, 2.1 |
| REQ-4 (sans classe → vide) | 1.1c, 2.1 |
| REQ-5 (rôles non-étudiants) | 1.3, 2.4 |
| REQ-6 (tenant) | 2.1 |
| REQ-7 (DI/DRY/≤40) | 2.1, 2.2, 4.2 |
