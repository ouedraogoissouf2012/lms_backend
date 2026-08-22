# Tasks — #575 · colonnes fantômes dans la recherche

Ordre TDD strict : chaque test est écrit et **exécuté en ROUGE** avant le code
qui le rend vert.

- [x] **1. Tests d'acceptation HTTP (ROUGE attendu)**
  - [x] 1.1 Créer `tests/Feature/Search/SearchTeacherScopingTest.php` : deux
        enseignants de la même institution, une leçon et une évaluation chacun.
        _Requirements: REQ-1, REQ-2, REQ-3, REQ-4, REQ-8_
  - [x] 1.2 Ajouter le cas enseignant sans `klassci_enseignant_id` (fail-closed).
        _Requirements: REQ-5_
  - [x] 1.3 Ajouter la couverture `/api/search/suggestions`.
        _Requirements: REQ-6_
  - [x] 1.4 Exécuter la suite et **consigner la sortie ROUGE** (nombre d'échecs
        et messages) dans la description de PR.

- [x] **2. Collaborateur d'appartenance**
  - [x] 2.1 Créer `tests/Unit/Services/Search/TeacherOwnershipScopeTest.php` :
        résultat de la requête pour les leçons, pour les évaluations et pour la
        branche fail-closed. Assertions sur les lignes retournées et non sur le
        SQL : `toSql()` diffère entre SQLite et MySQL, le comportement non.
        _Requirements: REQ-1, REQ-3, REQ-5_
  - [x] 2.2 Créer `app/Services/Search/TeacherOwnershipScope.php` avec la
        décision d'identité du design §1 en docblock (critère de fermeture #575).
        _Requirements: REQ-1, REQ-3, REQ-5_

- [x] **3. Câblage `GlobalSearchService`**
  - [x] 3.1 Injecter `TeacherOwnershipScope` ; remplacer la closure de
        `searchLessons()`. _Requirements: REQ-1, REQ-2_
  - [x] 3.2 Remplacer la closure de `searchEvaluations()` ; corriger
        `title` → `titre` dans le filtre et dans la valeur du payload.
        _Requirements: REQ-3, REQ-4, REQ-8_
  - [x] 3.3 Mettre à jour le docblock de classe (l.30-35) qui présentait les
        « bugs historiques » comme conservés volontairement.

- [x] **4. Câblage `SearchSuggestionsService`**
  - [x] 4.1 Injecter `TeacherOwnershipScope` ; remplacer les deux closures ;
        corriger `title` → `titre` (filtre + `pluck`). _Requirements: REQ-6_

- [x] **5. Validation**
  - [x] 5.1 `vendor/bin/phpunit tests/Feature/Search tests/Unit/Services/Search`
        → 100 % vert (VERT après ROUGE, transition consignée).
  - [x] 5.2 Suite complète impactée : `tests/Feature/Search`,
        `tests/Feature/Requests/GlobalSearchRequestTest.php`,
        `tests/Feature/Security/SearchThrottleTest.php`, plus les tests des
        domaines leçons/évaluations touchés par aucune modification mais
        vérifiés par prudence. _Règle mémoire : `feedback_run_full_suite_before_push`_
  - [x] 5.3 `vendor/bin/phpstan analyse --memory-limit=2G` → 0 erreur.
  - [x] 5.4 `php scripts/check-file-sizes.php` sur les fichiers modifiés.
  - [x] 5.5 Revue qualité (`/thermo-nuclear-code-quality-review`, sinon
        `production-grade-standards` + `/code-review`).

- [ ] **6. Livraison**
  - [x] 6.1 `git add -f .claude/specs/575-*` (les `*.md` sont gitignorés).
  - [ ] 6.2 Commit conventionnel + `Co-Authored-By`, **après accord explicite**
        du user. _Règle mémoire : `feedback_no_commit_without_approval`_
  - [ ] 6.3 PR vers `lms`, avec les 5 constats hors périmètre déclarés en clair
        et la mention que la validation MySQL dépend de #574.
  - [ ] 6.4 Reporter le n° de PR à la fenêtre orchestratrice.
