# Tasks — #608 · Divergences SQLite/MySQL : présences vidéo + dashboard étudiant

Ordre TDD : le RED est établi **avant** toute modification de `app/`.

- [x] **1. Reproduire le RED sous MySQL 8.4** _(Requirements: R5)_
  - [x] 1.1 `mysql:8.4` local, `sql_mode` session vérifié (`ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,…`), 69 migrations vertes
  - [x] 1.2 Les 3 fichiers d'acceptation lancés **dans un seul processus** → 5 échecs, identiques à la jambe MySQL de #603
  - [x] 1.3 Capturer le SQL exact de chaque `QueryException` et le consigner dans `requirements.md`
  - [x] 1.4 Falsifier l'hypothèse de l'issue : `grep groupBy` sur les 2 chemins → 0 occurrence

- [x] **2. Cause A — source unique de « tentative terminée »** _(Requirements: R3)_
  - [x] 2.1 `app/Models/QuizAttempt.php` : `const COMPLETED_STATUSES` + `scopeCompleted()` avec docblock d'avertissement sur la valeur fantôme `completed`
  - [x] 2.2 `app/Services/Dashboard/TeacherDashboardService.php:206` : littéral → `->completed()` (couvert par un test déjà vert)
  - [x] 2.3 Sweep : 5 autres sites du littéral relevés (`QuizAccessService`, `QuizStatisticsService`) — **sains**, laissés en dette tracée (requirements §5)

- [x] **3. Cause A — tests AVANT correctif** _(Requirements: R7)_
  - [x] 3.1 Créer `tests/Unit/Services/Dashboard/StudentDashboardServiceTest.php` (convention locale : `final`, `RefreshDatabase`, `TenantManager` en `setUp()`)
  - [x] 3.2 `test_quiz_block_uses_real_columns_and_attempt_statuses` → `total_attempts = 3`, `average_score = 70.0`
  - [x] 3.3 `test_quiz_block_ignores_other_students_and_unfinished_attempts` → `in_progress`/`abandoned`/autre étudiant exclus
  - [x] 3.4 Vérifié ROUGE sur le code d'avant (`0` au lieu de `3` / `1`) — y compris sous SQLite : les compteurs étaient inertes sur les DEUX moteurs

- [x] **4. Cause A — correctif du dashboard étudiant** _(Requirements: R1, R2)_
  - [x] 4.1 `StudentDashboardService.php:187,191` : `->completed()` + `avg('score')`, commentaire citant `QuizGradingService.php:177`
  - [x] 4.2 Les nouveaux tests au VERT sous SQLite puis sous MySQL

- [x] **5. Cause B — portabilité des tests présences** _(Requirements: R4, R6)_
  - [x] 5.1 `SyncAttendancesRequestTest` : propriété `$seance`, 13 occurrences `'seance_cours_id' => 1` → `$this->seance->id`
  - [x] 5.2 `AttendancesSyncNoNPlusOneTest` : idem dans `syncWith()`
  - [x] 5.3 Commentaire expliquant POURQUOI (InnoDB ne rollback pas `AUTO_INCREMENT`), pour que le piège ne revienne pas

- [x] **6. Revue de code — défaut A3 trouvé et corrigé** _(Requirements: R2, R7)_
  - [x] 6.1 `/code-review` (repli de `/thermo-nuclear-code-quality-review`, absent de la session) exécuté sur la worktree
  - [x] 6.2 Test rouge d'abord : `test_upcoming_quizzes_excludes_quizzes_the_student_already_finished`
  - [x] 6.3 `StudentDashboardService.php:140` : filtre mort → `whereIn('status', QuizAttempt::COMPLETED_STATUSES)`
  - [x] 6.4 Cas limite documenté par un test : `test_average_score_falls_back_to_zero_while_attempts_await_grading`
  - [x] 6.5 Findings hors périmètre consignés en requirements §5 (4 dettes) — signalés, non corrigés

- [ ] **7. Validation croisée** _(Requirements: R5, R6)_
  - [x] 7.1 Les 5 tests d'acceptation VERTS **sous MySQL**, un seul processus (28 tests / 79 assertions)
  - [x] 7.2 `tests/Unit/Services/Dashboard/` + `tests/Feature/Dashboard/` VERTS sous SQLite (36 / 128)
  - [x] 7.3 Suite impactée sous SQLite (`Requests`, `Performance`, `Unit/Services/Quiz`, `Quiz`, `E2E`) : 487 tests, 0 erreur, 1 skip d'environnement (Redis)
  - [x] 7.4 Sweep `grep -rn "QuizAttempt::" app/` — aucun autre appelant impacté
  - [x] 7.5 Suite **complète** sous MySQL : **1778 tests, 6001 assertions, 1 échec, 4 skips
        d'environnement**. Les 5 échecs de #608 ont disparu. L'unique échec restant
        (`QueueDrainCommandTest`) est **pré-existant et sans lien** — cause prouvée
        (`EXIT_MEMORY_LIMIT = 12` vs `--memory=128` par défaut, suite à 196 Mo), consignée
        en requirements §5
  - [x] 7.6 Suite **complète** sous SQLite : **1778 tests, 6006 assertions, 1 échec, 3 skips**
        — le **même** `QueueDrainCommandTest`, à la **même** mémoire (196 Mo). L'échec est
        donc indépendant du moteur, ce qui clôt la question de son rattachement à #608
  - [x] 7.8 CI de la PR : **10/10 verte** (dont PHPStan avec la baseline purgée et le
        file-size guard). ⚠️ La jambe MySQL n'y figure pas : elle vit dans #603, non mergée
  - [x] 7.7 `vendor/bin/phpstan analyse --memory-limit=2G` → 0 erreur, **1 entrée de baseline morte purgée** (`argument.type` / `'status'` sur `StudentDashboardService`)

- [ ] **8. Livraison**
  - [x] 8.1 Contrôles §3 : `QuizAttempt` 148 l ≤150, services ≤300, méthodes ≤40 l, aucun `getMessage()` ajouté, aucun `dd()`
  - [ ] 8.2 `git add -f .claude/specs/608-*` (dossier ignoré par défaut) + stager le nouveau fichier de test
  - [ ] 8.3 Supprimer le harnais local `run-mysql-tests.sh` (contient `APP_KEY` et identifiants DB, non gitignoré) — commandes reportées dans la description de PR
  - [ ] 8.4 Commit conventionnel (≤70 car., sujet minuscule) + PR vers `lms` — **après accord explicite du user**
  - [ ] 8.5 Reporter le n° de PR à l'orchestrateur + les 4 dettes hors périmètre
