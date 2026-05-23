# Migrate role checks to enum — Tasks

> Spec parent : [`requirements.md`](./requirements.md), [`design.md`](./design.md). Issue : [#132](https://github.com/ouedraogoissouf2012/lms_backend/issues/132).

## Stratégie de découpage

**1 seule PR mécanique** sur ~40 fichiers. Scope ~85 sites disséminés migrés + 1 extension enum (REQ-1). Net code applicatif : **−20 LOC**. Refactor pur, comportement runtime préservé.

**Ordre d'exécution** : étapes séquentielles regroupées par catégorie de fichier (FormRequests d'abord car patterns les plus uniformes, puis Controllers, puis Services/Concerns).

## Tâches

- [ ] **1. Étendre `App\Enums\Role` pour accepter l'alias `'étudiant'` (avec accent)**
  - [ ] 1.1 Modifier `app/Enums/Role.php` ligne 62 : ajouter `'étudiant'` dans le case `match` de `tryFromString`. Doit devenir `'etudiant', 'student', 'étudiant' => self::Etudiant`. _Requirements: REQ-1_
  - [ ] 1.2 Mettre à jour le PHPDoc de classe `Role.php:14-23` pour documenter le nouvel alias accepté. _Requirements: REQ-1_
  - [ ] 1.3 `php -l app/Enums/Role.php` → No syntax errors. _Requirements: REQ-1_

- [ ] **2. Tests Unit additionnel pour le nouvel alias**
  - [ ] 2.1 Dans `tests/Unit/Enums/RoleTest.php`, ajouter le test `test_try_from_string_accepts_accented_etudiant_alias` qui asserte `Role::tryFromString('étudiant') === Role::Etudiant`. _Requirements: REQ-8_
  - [ ] 2.2 `vendor/bin/phpunit tests/Unit/Enums/RoleTest.php` → 10 tests verts (9 existants + 1 nouveau). _Requirements: REQ-8_

- [ ] **3. Migration FormRequests admin globaux (`['superAdmin']` → Role::Supradmin)**
  - [ ] 3.1 Migrer les 7 FormRequests qui font `auth()->check() && in_array(auth()->user()->role, ['superAdmin'])` :
    - `AuthorizeServiceRequest`, `ChangeRoleRequest`, `ConnectServiceRequest`, `DeleteConfigurationRequest`, `DisableUserRequest`, `DisconnectServiceRequest`, `EnableUserRequest`, `GetConfigurationRequest`, `TestServiceConnectionRequest`, `UpdateConfigurationRequest`, `UpdateInstitutionSettingsRequest`, `ViewAuditLogRequest`
    
    Pattern : `return auth()->check() && in_array(auth()->user()->role, ['superAdmin']);` → `return auth()->user()?->asRoleEnum() === Role::Supradmin;`
    
    Import `use App\Enums\Role;` à ajouter en haut de chaque fichier. _Requirements: REQ-4_
  - [ ] 3.2 `php -l` sur chacun de ces fichiers + `grep -rn "in_array.*\['superAdmin'\]"` → 0 hit dans `app/Http/Requests/` post-3.1. _Requirements: REQ-6_

- [ ] **4. Migration FormRequests admin élargi (`coordinateur || superAdmin/admin`)**
  - [ ] 4.1 Migrer les FormRequests avec pattern `in_array(auth()->user()->role, ['coordinateur', 'superAdmin'])` :
    - `BulkImportUsersRequest`, `CreateUserRequest`, `DeleteUserRequest`, `ExportUsersRequest`, `ResetPasswordRequest`, `UpdateUserRequest`, `ViewUserDetailsRequest`
    
    Pattern : `return auth()->check() && in_array(auth()->user()->role, ['coordinateur', 'superAdmin']);` → `$u = auth()->user(); return $u !== null && ($u->isCoordinator() || $u->isAdmin());` _Requirements: REQ-3_
  - [ ] 4.2 Migrer les FormRequests admin reports (pattern `['coordinateur', 'superAdmin', 'admin']`) :
    - `GenerateActivityReportRequest`, `GenerateAttendanceReportRequest`, `GenerateGradesReportRequest`
    - `GetActivityTrendsRequest`, `GetPendingTasksRequest`, `GetRecentUsersRequest`, `GetSystemMetricsRequest`
    
    Pattern : `return in_array($user->role, ['coordinateur', 'superAdmin', 'admin']);` → `return $user->isCoordinator() || $user->isAdmin();` (équivalent car `isAdmin()` couvre `admin` ET `superAdmin`) _Requirements: REQ-3_
  - [ ] 4.3 `GetTeacherStatsRequest` : pattern `['teacher', 'enseignant', 'coordinateur', 'admin', 'superAdmin']` → `$user->isTeacher() || $user->isCoordinator() || $user->isAdmin()`. _Requirements: REQ-3_

- [ ] **5. Migration FormRequests Visio + autres**
  - [ ] 5.1 Migrer les FormRequests visio :
    - `ActivateVisioRequest`, `EndVisioRequest`, `StartVisioRequest` : pattern `!in_array($user->role, ['enseignant', 'teacher', 'coordinateur'])` → `!($user->isTeacher() || $user->isCoordinator())`. _Requirements: REQ-3_
    - `DeactivateVisioRequest` : pattern `$user->role !== 'enseignant' && $user->role !== 'teacher'` → `!$user->isTeacher()`. _Requirements: REQ-5_
    - `ToggleVisioSeanceRequest` : pattern `!in_array($user->role, ['coordinateur', 'superAdmin'])` → `!($user->isCoordinator() || $user->isAdmin())`. _Requirements: REQ-3_
  - [ ] 5.2 Migrer les FormRequests seance :
    - `HideSeanceRequest`, `UnhideSeanceRequest` : pattern `$user->role !== 'etudiant' && $user->role !== 'student'` → `!$user->isStudent()`. _Requirements: REQ-5_
    - `DeleteSeanceRequest` (2 sites mixtes) : pattern selon le contexte. _Requirements: REQ-3, REQ-5_
  - [ ] 5.3 Migrer `StoreEvaluationRequest:40` : `$user->role === 'coordinateur'` → `$user->isCoordinator()`. _Requirements: REQ-5_
  - [ ] 5.4 Migrer `StoreQuizRequest` : pattern `['enseignant', 'coordinateur', 'superAdmin', 'admin']` → `$user->isTeacher() || $user->isCoordinator() || $user->isAdmin()`. _Requirements: REQ-3_
  - [ ] 5.5 Migrer `SyncNotesToKlassciRequest`, `SyncToKlassciRequest` : pattern `['enseignant', 'coordinateur', 'superAdmin']` → idem 5.4 sans le `admin` explicite (équivalent post-migration car isAdmin couvre). _Requirements: REQ-3_
  - [ ] 5.6 Migrer `CreateNotificationRequest` (2 sites : `=== 'supradmin'` + `in_array(['coordinateur', 'superAdmin', 'supradmin'])`) → `=== Role::Supradmin` + `$user->isCoordinator() || $user->isAdmin()`. _Requirements: REQ-4, REQ-3_

- [ ] **6. Migration Controllers (non-LMS)**
  - [ ] 6.1 `AuthController:135` : `$user->role === 'supradmin'` → `$user->asRoleEnum() === Role::Supradmin`. _Requirements: REQ-5_
  - [ ] 6.2 `EvaluationController:1481` : `$user && $user->role === 'coordinateur'` → `$user?->isCoordinator()` (avec null-safe). _Requirements: REQ-5_
  - [ ] 6.3 `FileController:291` : `$caller->role === 'supradmin'` → `$caller->asRoleEnum() === Role::Supradmin`. _Requirements: REQ-5_
  - [ ] 6.4 `NotificationsController:234` : idem 6.3. _Requirements: REQ-5_
  - [ ] 6.5 `SearchController` (8 sites — le fichier le plus complexe) :
    - Lignes 42, 213 : `in_array($user->role, ['coordinateur', 'superAdmin'])` → `$user->isCoordinator() || $user->isAdmin()`. _Requirements: REQ-3_
    - Lignes 122, 150 : `in_array($user->role, ['coordinateur', 'superAdmin', 'enseignant'])` → `$user->isCoordinator() || $user->isAdmin() || $user->isTeacher()`. _Requirements: REQ-3_
    - Lignes 70, 99, 224, 236 : `$user->role === 'enseignant'` → `$user->isTeacher()`. _Requirements: REQ-5_
    - Lignes 74, 103 : `$user->role === 'étudiant' || $user->role === 'student'` → `$user->isStudent()` (couvre les 3 cas grâce à REQ-1). _Requirements: REQ-5_
  - [ ] 6.6 `TeacherStatsController:29` : `!in_array($user->role, ['enseignant', 'teacher', 'coordinateur', 'superAdmin'])` → `!($user->isTeacher() || $user->isCoordinator() || $user->isAdmin())`. _Requirements: REQ-3_

- [ ] **7. Migration Controllers LMS**
  - [ ] 7.1 `LMSAttendancesController` (3 sites lignes 168, 173, 303) : `$user->role === 'enseignant'`/`'etudiant'` → helpers. _Requirements: REQ-5_
  - [ ] 7.2 `LMSMatieresController` (4 sites) :
    - L152 : `in_array($user->role, ['enseignant', 'teacher'])` → `$user->isTeacher()`
    - L171 : `in_array($user->role, ['etudiant', 'student'])` → `$user->isStudent()`
    - L214 : `$user->role === 'etudiant'` → `$user->isStudent()`
    - L363 : `in_array($user->role, ['etudiant', 'student'])` → `$user->isStudent()`
    - L522 : `!in_array($user->role, ['admin', 'coordinateur', 'superAdmin'], true)` → `!($user->isCoordinator() || $user->isAdmin())`. _Requirements: REQ-3, REQ-5_
  - [ ] 7.3 `LMSNotificationsPreferencesController:55` : `in_array($currentUser->role, ['coordinateur', 'superAdmin'], true)` → `$currentUser->isCoordinator() || $currentUser->isAdmin()`. _Requirements: REQ-3_
  - [ ] 7.4 `LMSSeancesController` (6 sites) :
    - L152, L1357 : `$user->role === 'etudiant'/'enseignant'` → helpers. _Requirements: REQ-5_
    - L291 : `in_array($user->role, ['enseignant', 'teacher'])` → `$user->isTeacher()`. _Requirements: REQ-3_
    - L442 : `in_array($userToValidate->role, ['enseignant', 'coordinateur', 'superAdmin', 'teacher'])` → `$userToValidate->isTeacher() || $userToValidate->isCoordinator() || $userToValidate->isAdmin()`. _Requirements: REQ-3_
    - L451 : `in_array($userToValidate->role, ['coordinateur', 'superAdmin'])` → `$userToValidate->isCoordinator() || $userToValidate->isAdmin()`. _Requirements: REQ-3_
    - L457 : `in_array($userToValidate->role, ['etudiant', 'étudiant', 'student'])` → `$userToValidate->isStudent()` (via REQ-1). _Requirements: REQ-3_
  - [ ] 7.5 `LMSVisioController` (2 sites) :
    - L76 : `in_array($user->role, ['enseignant', 'teacher'])` → `$user->isTeacher()`. _Requirements: REQ-3_
    - L495 : `$user->role === 'coordinateur'` → `$user->isCoordinator()`. _Requirements: REQ-5_

- [ ] **8. Migration Services + Concerns**
  - [ ] 8.1 `app/Services/SeanceQueryService.php` (4 sites) :
    - L121 : `in_array($user->role, ['enseignant', 'teacher', 'coordinateur', 'superAdmin'], true)` → `$user->isTeacher() || $user->isCoordinator() || $user->isAdmin()`. _Requirements: REQ-3_
    - L163 : `in_array($user->role, ['enseignant', 'teacher'], true)` → `$user->isTeacher()`. _Requirements: REQ-3_
    - L167, L312 : `$user->role === 'etudiant'` → `$user->isStudent()`. _Requirements: REQ-5_
  - [ ] 8.2 `app/Http/Requests/Concerns/ChecksEvaluationOwnership.php:82` : `$user->role === 'coordinateur'` → `$user->isCoordinator()`. Attention : trait critique sécurité, vérifier que le test pré-existant `ChecksEvaluationOwnershipTest::test_returns_false_for_coordinateur` reste vert. _Requirements: REQ-5_
  - [ ] 8.3 `app/Http/Requests/Concerns/ChecksFileAuthorization.php` (4 sites lignes 58, 89, 74, 101) :
    - L58, L89 : `$user->role === 'supradmin'` → `$user->asRoleEnum() === Role::Supradmin`. _Requirements: REQ-5_
    - L74, L101 : `in_array($user->role, ['admin', 'administrateur', 'superAdmin'], true)` → `$user->isAdmin()`. **Bug fix** : élargit à `'supradmin'` (cohérent avec enum). Documenter dans le commit. _Requirements: REQ-3, design §5.2_
  - [ ] 8.4 `app/Http/Requests/Concerns/ChecksForumAuthorization.php:82` : `$user->role === 'supradmin'` → `$user->asRoleEnum() === Role::Supradmin`. La ligne 52 est dans la PHPDoc (mention textuelle), à ne PAS modifier ou à mettre à jour la doc. _Requirements: REQ-5_

- [ ] **9. PHP lint exhaustif**
  - [ ] 9.1 `php -l` sur **tous** les fichiers modifiés (commande `find` + `xargs` ou check via `composer check`). _Requirements: critère §1_

- [ ] **10. Audit grep final exhaustif**
  - [ ] 10.1 `grep -rn "in_array.*->role.*\['" app/` → **0 hit** (sauf commentaires/docs). _Requirements: REQ-6_
  - [ ] 10.2 `grep -rn "->role ===\|->role !==" app/` → **0 hit** (sauf commentaires/docs). _Requirements: REQ-6_
  - [ ] 10.3 Si hits restants, lister les fichiers manqués et migrer. _Requirements: REQ-6_
  - [ ] 10.4 `grep -rn "use App\\\\Enums\\\\Role;" app/` → un compte cohérent (~30-40 imports ajoutés). _Requirements: critère §1_

- [ ] **11. Régression Feature/Unit**
  - [ ] 11.1 `vendor/bin/phpunit tests/Unit/Enums/RoleTest.php` → 10 tests verts (9 + 1 nouveau alias `'étudiant'`). _Requirements: REQ-7_
  - [ ] 11.2 `vendor/bin/phpunit tests/Unit/Middleware/EnsureKlassciSyncTest.php` → 10 tests pré-existants intacts. _Requirements: REQ-7_
  - [ ] 11.3 `vendor/bin/phpunit tests/Feature/Security` → 28 tests pré-existants intacts. _Requirements: REQ-7_
  - [ ] 11.4 `vendor/bin/phpunit tests/Feature/Models/UserRoleHelpersTest.php` → 4 tests intacts. _Requirements: REQ-7_
  - [ ] 11.5 `vendor/bin/phpunit tests/Feature/LMS` → 50 tests intacts. _Requirements: REQ-7_
  - [ ] 11.6 `vendor/bin/phpunit tests/Feature/Quiz tests/Feature/Forum tests/Feature/Notifications tests/Feature/Files` → suites intactes. _Requirements: REQ-7_

- [ ] **12. PHPStan check**
  - [ ] 12.1 `vendor/bin/phpstan analyse --no-progress --memory-limit=2G` → `[OK] No errors`. Aucune nouvelle violation ; PHPStan peut désormais vérifier statiquement que `Role::X` est une case valide partout. _Requirements: critère d'acceptation §2_

- [ ] **13. Audits read-only**
  - [ ] 13.1 Lancer `spec-security` + `spec-architect` en parallèle sur le diff. Cible : 0 finding HIGH/CRITICAL. Le finding LOW DRY de #121a doit **complètement disparaître**. _Requirements: critères §5 + §6_
  - [ ] 13.2 Corriger les findings éventuels AVANT 13.3.
  - [ ] 13.3 Lancer `spec-reviewer` (15 questions). Verdict cible : MERGE-READY. _Requirements: critère §7_

- [ ] **14. Validation locale finale**
  - [ ] 14.1 `git diff lms..HEAD --stat`. Bilan attendu : ~40 fichiers app + 2 fichiers tests + 3 spec docs. Net code applicatif ~−20 LOC. _Requirements: critère §1_

- [ ] **15. Commit + push + PR + fermeture #132**
  - [ ] 15.1 Présenter le récap des changements à l'utilisateur AVANT `git commit` (rule `feedback_no_commit_without_approval`).
  - [ ] 15.2 Sur approbation explicite, créer 1 commit Conventional Commit type `refactor` avec mention `closes #132` dans le body. Mentionner explicitement les 3 bug fixes silencieux (design §5.2). _Requirements: critère §8_
  - [ ] 15.3 `git push -u origin refactor/132-migrate-role-checks-to-enum`.
  - [ ] 15.4 `gh pr create --base lms --title "refactor: migrate 85 disseminated role checks to User::isXxx() / Role enum (closes #132, part b/b)"` avec body-file détaillé.
  - [ ] 15.5 Attendre que l'utilisateur merge la PR côté GitHub. Pas de `gh pr merge --auto`.
  - [ ] 15.6 Post-merge : `gh issue close 132 -c "Résolu par PR #XXX..."`.

## Récap mapping `_Requirements_`

| REQ | Tasks |
|---|---|
| REQ-1 (enum extension `'étudiant'`) | 1.1, 1.2, 1.3 |
| REQ-2 (in_array simple → isXxx) | 4.1, 5.5, 7.1, 7.2, 7.3, 7.4, 7.5, 8.1 |
| REQ-3 (in_array mixte → chaîne isXxx) | 3, 4.2, 4.3, 5.1, 5.2, 5.4, 5.5, 5.6, 6.5, 6.6, 7.4, 8.1, 8.3 |
| REQ-4 (in_array supradmin → Role::Supradmin) | 3.1, 5.6 |
| REQ-5 (=== / !== → isXxx ou enum) | 4.2, 5.1, 5.2, 5.3, 6.1-6.6, 7.1, 7.2, 7.4, 7.5, 8.1, 8.2, 8.3, 8.4 |
| REQ-6 (audit grep final) | 10.1, 10.2, 10.3, 10.4 |
| REQ-7 (régression) | 11.1-11.6 |
| REQ-8 (test Unit nouvel alias) | 2.1, 2.2 |

## Estimation et risques

- **Temps estimé** : ~3h en exécution séquentielle.
- **Risque principal** : régression silencieuse sur 1 site spécifique. **Mitigation** : tâche 10 (audit grep) + tâche 11 (régression exhaustive ~91 tests).
- **Risque secondaire** : un bug fix silencieux (cf. design §5.2) casse un test pré-existant qui attendait l'ancien comportement bogué. **Mitigation** : si test casse, analyser cas par cas — corriger le test (s'il testait un bug) ou revenir au comportement strict du site.
- **Risque tertiaire** : conflit de merge si une autre PR touche les mêmes fichiers. **Mitigation** : aucune PR ouverte sur ces fichiers actuellement.
