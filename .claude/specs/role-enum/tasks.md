# Role enum — Tasks

> Spec parent : [`requirements.md`](./requirements.md), [`design.md`](./design.md). Issue : [#121](https://github.com/ouedraogoissouf2012/lms_backend/issues/121).

## Stratégie de découpage

**1 seule PR chirurgicale (#121a)**. Scope ~+95 LOC code applicatif net + ~+240 LOC tests. La PR follow-up #121b (migration des 77 sites disséminés) sera ouverte post-merge comme issue séparée.

**Ordre d'exécution** strictement séquentiel. Risque principal : casser le comportement runtime de `User::isXxx()` ou `EnsureKlassciSync::isEscalationAttempt()` → preuve par régression Feature/Unit pré-existantes.

## Tâches

- [ ] **1. Créer l'enum `App\Enums\Role`**
  - [ ] 1.1 Créer le sous-dossier `app/Enums/` (sera vide jusqu'à présent). _Requirements: REQ-1_
  - [ ] 1.2 Créer `app/Enums/Role.php` avec :
    - `<?php declare(strict_types=1);` + `namespace App\Enums;`
    - PHPDoc complet (cf. design §2.1) référençant issue #121, alias EN/FR documentés, séparation API surface enum/model, migration en 2 PRs (#121a/#121b)
    - 5 cases canoniques FR : `Etudiant='etudiant'`, `Enseignant='enseignant'`, `Coordinateur='coordinateur'`, `Admin='admin'`, `Supradmin='supradmin'`
    - `static tryFromString(?string $value): ?self` avec mapping `match` (10 alias + null + default)
    - `permissivity(): int` avec mapping `match` (5 cases → 1-5)
    - `isAdmin(): bool` retourne `$this === self::Admin || $this === self::Supradmin`
    - `isMorePermissiveThan(self $other): bool` retourne `$this->permissivity() > $other->permissivity()`
    - _Requirements: REQ-1, REQ-2, REQ-3, REQ-8_
  - [ ] 1.3 `php -l app/Enums/Role.php` → No syntax errors. _Requirements: REQ-1_

- [ ] **2. Migrer `User` model**
  - [ ] 2.1 Dans `app/Models/User.php`, ajouter `use App\Enums\Role;` en haut de fichier (après les imports existants). _Requirements: REQ-4_
  - [ ] 2.2 Ajouter méthode `public function asRoleEnum(): ?Role { return Role::tryFromString($this->role); }` (placée juste avant `isTeacher()`). PHPDoc 1 ligne expliquant son rôle. _Requirements: REQ-4_
  - [ ] 2.3 Refactoriser `isTeacher()` : `return $this->asRoleEnum() === Role::Enseignant;`. Retirer l'ancienne logique `$this->role === 'enseignant' || $this->role === 'teacher'`. _Requirements: REQ-4_
  - [ ] 2.4 Refactoriser `isCoordinator()` : `return $this->asRoleEnum() === Role::Coordinateur;`. Idem. _Requirements: REQ-4_
  - [ ] 2.5 Refactoriser `isStudent()` : `return $this->asRoleEnum() === Role::Etudiant;`. Idem. _Requirements: REQ-4_
  - [ ] 2.6 Refactoriser `isAdmin()` : `return $this->asRoleEnum()?->isAdmin() ?? false;`. Retirer `in_array($this->role, [...])`. _Requirements: REQ-4_
  - [ ] 2.7 `php -l app/Models/User.php` → No syntax errors. _Requirements: REQ-4_

- [ ] **3. Migrer `EnsureKlassciSync` middleware**
  - [ ] 3.1 Dans `app/Http/Middleware/EnsureKlassciSync.php`, ajouter `use App\Enums\Role;` en haut de fichier. _Requirements: REQ-5_
  - [ ] 3.2 **Retirer entièrement** la constante `private const ROLE_PERMISSIVITY = [...]` (10 entrées + commentaire associé). _Requirements: REQ-5_
  - [ ] 3.3 Refactoriser `isEscalationAttempt(?string $lmsRole, ?string $klassciRole): bool` pour utiliser l'enum :
    ```php
    $lmsLevel     = Role::tryFromString($lmsRole)?->permissivity() ?? 0;
    $klassciLevel = Role::tryFromString($klassciRole)?->permissivity() ?? 0;
    return $klassciLevel > $lmsLevel;
    ```
    _Requirements: REQ-5_
  - [ ] 3.4 Mettre à jour la PHPDoc de classe : la mention « hiérarchie de permissivité interne » devient « hiérarchie définie dans `App\Enums\Role::permissivity()` ». _Requirements: REQ-5_
  - [ ] 3.5 `php -l app/Http/Middleware/EnsureKlassciSync.php` → No syntax errors. _Requirements: REQ-5_

- [ ] **4. Tests Unit de l'enum**
  - [ ] 4.1 Créer le sous-dossier `tests/Unit/Enums/`. _Requirements: REQ-6_
  - [ ] 4.2 Créer `tests/Unit/Enums/RoleTest.php` avec :
    - `<?php declare(strict_types=1);`
    - `namespace Tests\Unit\Enums;`
    - `extends PHPUnit\Framework\TestCase` (PAS `Tests\TestCase` car pas de DB requise)
    - `use App\Enums\Role;`
    - _Requirements: REQ-6_
  - [ ] 4.3 Implémenter `test_cases_have_expected_canonical_values` : asserter les 5 valeurs string `Role::Etudiant->value === 'etudiant'`, etc. _Requirements: REQ-6 #1_
  - [ ] 4.4 Implémenter `test_try_from_string_returns_canonical_for_fr_input` : `Role::tryFromString('etudiant') === Role::Etudiant`, idem pour 4 autres canoniques FR. _Requirements: REQ-6 #2_
  - [ ] 4.5 Implémenter `test_try_from_string_normalizes_en_aliases` : `Role::tryFromString('student') === Role::Etudiant`, `'teacher' === Enseignant`, `'coordinator' === Coordinateur`. _Requirements: REQ-6 #3_
  - [ ] 4.6 Implémenter `test_try_from_string_normalizes_admin_aliases` : `'administrateur' === Admin`, `'superAdmin' === Supradmin`. _Requirements: REQ-6 #4_
  - [ ] 4.7 Implémenter `test_try_from_string_returns_null_for_invalid` : `tryFromString('hacker') === null`, `tryFromString(null) === null`, `tryFromString('') === null`. _Requirements: REQ-6 #5_
  - [ ] 4.8 Implémenter `test_permissivity_returns_expected_levels` : 5 assertions sur les valeurs 1-5. _Requirements: REQ-6 #6_
  - [ ] 4.9 Implémenter `test_is_admin_returns_true_for_admin_and_supradmin_only` : 5 assertions (Admin/Supradmin true, 3 autres false). _Requirements: REQ-6 #7_
  - [ ] 4.10 Implémenter `test_is_more_permissive_than` : 4 cas (Supradmin > Etudiant true, Etudiant > Supradmin false, Admin > Coordinateur true, Enseignant > Admin false). _Requirements: REQ-6 #8_
  - [ ] 4.11 Implémenter `test_is_more_permissive_than_same_role_returns_false` : `Etudiant->isMorePermissiveThan(Etudiant) === false` (strict `>`, pas `>=`). _Requirements: REQ-6 #9_
  - [ ] 4.12 `php -l tests/Unit/Enums/RoleTest.php` + `vendor/bin/phpunit tests/Unit/Enums/RoleTest.php` → 9 tests **passent** (pas de skip, l'enum est pure). _Requirements: REQ-6_

- [ ] **5. Test Feature régression User**
  - [ ] 5.1 Créer le sous-dossier `tests/Feature/Models/` si pas présent. _Requirements: REQ-6 #10_
  - [ ] 5.2 Créer `tests/Feature/Models/UserRoleHelpersTest.php` avec :
    - `<?php declare(strict_types=1);`
    - `namespace Tests\Feature\Models;`
    - `extends Tests\TestCase`
    - `use RefreshDatabase`
    - Skip si `!extension_loaded('pdo_pgsql')`
    - _Requirements: REQ-6 #10_
  - [ ] 5.3 Implémenter `test_user_helpers_handle_all_canonical_and_alias_roles` : pour chacune des 10 valeurs DB possibles (5 canoniques FR + 5 alias EN/admin variants), créer un User factory et asserter que les 4 méthodes `isXxx()` retournent les bons booléens. Au moins 10 × 4 = 40 assertions. _Requirements: REQ-6 #10_
  - [ ] 5.4 Implémenter `test_user_helpers_return_false_for_unknown_role` : User avec `role='hacker'` → toutes les 4 méthodes retournent `false`. _Requirements: REQ-4_
  - [ ] 5.5 Implémenter `test_user_helpers_return_false_for_null_role` : User avec `role=null` → toutes les 4 méthodes retournent `false`. _Requirements: REQ-4_
  - [ ] 5.6 `php -l tests/Feature/Models/UserRoleHelpersTest.php` → No syntax errors. _Requirements: REQ-6_

- [ ] **6. Régression Unit/Feature**
  - [ ] 6.1 `vendor/bin/phpunit tests/Unit/Middleware/EnsureKlassciSyncTest.php` → 10 tests pré-existants (#118) intacts (preuve middleware refactor OK). _Requirements: REQ-7_
  - [ ] 6.2 `vendor/bin/phpunit tests/Feature/Security` → 28 tests Security pré-existants intacts. _Requirements: REQ-7_
  - [ ] 6.3 `vendor/bin/phpunit tests/Feature/LMS` → 50 tests LMS intacts. _Requirements: REQ-7_
  - [ ] 6.4 `vendor/bin/phpunit tests/Unit/Enums tests/Feature/Models` → 9 Unit + 3 Feature nouveaux chargés. _Requirements: REQ-6_

- [ ] **7. PHPStan check**
  - [ ] 7.1 `vendor/bin/phpstan analyse --no-progress --memory-limit=2G` → `[OK] No errors`. Aucune nouvelle violation ; PHPStan peut désormais vérifier statiquement que `Role::X` est une case valide. _Requirements: critère d'acceptation §2_

- [ ] **8. Audits read-only**
  - [ ] 8.1 Lancer `spec-security` + `spec-architect` en parallèle sur le diff. Cible : 0 finding HIGH/CRITICAL. Le finding MEDIUM DRY identifié dans PR #118 doit **disparaître** (objectif de cette PR). _Requirements: critères §4 + §5_
  - [ ] 8.2 Corriger les findings éventuels AVANT 8.3.
  - [ ] 8.3 Lancer `spec-reviewer` (15 questions). Verdict cible : MERGE-READY. _Requirements: critère §6_

- [ ] **9. Validation locale finale**
  - [ ] 9.1 `git diff lms..HEAD --stat`. Bilan attendu : ~5-6 fichiers app + 2 fichiers tests + 3 spec docs ≈ +335 LOC net (+110 enum + −15 sur 2 sites racine + 240 tests + spec). _Requirements: critère §1_
  - [ ] 9.2 Audit grep `grep -rn "ROLE_PERMISSIVITY" app/` → 0 hit (constante supprimée). _Requirements: REQ-5_
  - [ ] 9.3 Audit grep `grep -rn "in_array.*\['admin'.*'administrateur'.*'superAdmin'.*'supradmin'\]" app/Models/User.php` → 0 hit (méthode `isAdmin` refactorée). _Requirements: REQ-4_

- [ ] **10. Commit + push + PR + fermeture #121 + création #121b**
  - [ ] 10.1 Présenter le récap des changements à l'utilisateur AVANT `git commit` (rule `feedback_no_commit_without_approval`).
  - [ ] 10.2 Sur approbation explicite, créer 1 commit Conventional Commit type `refactor` avec mention `closes #121` dans le body. Mentionner la PR follow-up #121b à créer. _Requirements: critère §7, §8_
  - [ ] 10.3 `git push -u origin refactor/121-role-enum`.
  - [ ] 10.4 `gh pr create --base lms --title "refactor: App\\Enums\\Role PHP 8.1 — centralize roles + alias EN/FR (closes #121, part a/b)"` avec body-file détaillé.
  - [ ] 10.5 Attendre que l'utilisateur merge la PR côté GitHub. Pas de `gh pr merge --auto`.
  - [ ] 10.6 Post-merge : `gh issue close 121 -c "Résolu par PR #XXX..."`.
  - [ ] 10.7 Post-merge : créer issue **#121b follow-up** « [refactor] Migrer les 77 sites `in_array(\$user->role, ...)` et `=== 'X'` vers `User::isXxx()` ou l'enum » avec body listant les ~15 fichiers concernés. _Requirements: critère §8_

## Récap mapping `_Requirements_`

| REQ | Tasks |
|---|---|
| REQ-1 (enum créé + 5 cases) | 1.1, 1.2, 1.3 |
| REQ-2 (`tryFromString` alias EN/FR) | 1.2, 4.4, 4.5, 4.6, 4.7 |
| REQ-3 (méthodes enum) | 1.2, 4.8, 4.9, 4.10, 4.11 |
| REQ-4 (User migration) | 2.1-2.7, 5.3, 5.4, 5.5 |
| REQ-5 (EnsureKlassciSync migration) | 3.1-3.5, 9.2 |
| REQ-6 (10 tests obligatoires) | 4.1-4.12, 5.1-5.6 |
| REQ-7 (régression) | 6.1, 6.2, 6.3 |
| REQ-8 (documentation enum) | 1.2 |

## Estimation et risques

- **Temps estimé** : ~2-3h en exécution séquentielle locale. Refactor pur, scope chirurgical.
- **Risque principal** : casser le comportement runtime de `User::isAdmin/isTeacher/etc.` via une erreur de typage ou de comparaison. **Mitigation** :
  - Tableau preuve équivalence dans `design.md §3.3` et `§4.3`
  - Tests Unit de l'enum (9 tests) couvrent toutes les valeurs canoniques + alias + null + invalid
  - Test Feature régression model (3 tests) couvre les 10 alias historiques + null + invalid via le model réel
  - Régression cross-suite (88 tests pré-existants) prouve que le comportement HTTP/middleware reste identique
- **Risque secondaire** : un site disséminé (parmi les 77 inchangés) compare `$user->role === 'student'` au lieu d'utiliser `isStudent()`. Comportement préservé parce qu'on a NOT touché ces sites — ils utilisent la string brute de la DB. **Mitigation** : aucun changement DB, aucune migration data → ces sites continuent à fonctionner identiquement.
- **Risque tertiaire** : PHPStan peut signaler de nouveaux types stricts sur l'enum (par ex. `?Role` qui aurait besoin d'un guard plus explicite). **Mitigation** : tâche 7.1 (PHPStan strict).
