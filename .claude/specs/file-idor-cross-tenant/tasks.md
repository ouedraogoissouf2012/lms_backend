# Tasks — FileController IDOR cross-tenant fix

**Issue GitHub** : [#102](https://github.com/ouedraogoissouf2012/lms_backend/issues/102)
**Branche** : `fix/file-idor-cross-tenant`
**Spec docs** : [requirements.md](requirements.md) · [design.md](design.md)

---

## 1. Préparation (déjà fait)

- [x] **1.1** Branche `fix/file-idor-cross-tenant` créée depuis lms à jour
- [x] **1.2** Spec workflow (req + design approuvés user)
- [x] **1.3** Investigation : 5 méthodes affectées (vs 3 initialement remontées)

---

## 2. Trait `ChecksFileAuthorization` → §2, C1

- [ ] **2.1** Créer `app/Http/Requests/Concerns/ChecksFileAuthorization.php`
- [ ] **2.2** Implémenter `canReadFile()` selon §2 du design
- [ ] **2.3** Implémenter `canModerateFile()` selon §2 du design
- [ ] **2.4** PHPDoc complet (rationale vs ChecksForumAuthorization, 5-step decision tree)
- [ ] **2.5** `php -l` → 0 errors

---

## 3. `FileController` → §3.1, 3.2, 3.3, C2, C3

- [ ] **3.1** Ajouter `use ChecksFileAuthorization;` dans la classe
- [ ] **3.2** `show()` : remplacer inline check par `canReadFile()` (§3.1)
- [ ] **3.3** `download()` : idem (§3.2)
- [ ] **3.4** `stats()` : `Request $request` → `$caller = $this->authenticatedUser($request)` → closure `$applyScope` (tenant + student-scope si applicable) → cache key per institution+role (§3.3)
- [ ] **3.5** `php -l app/Http/Controllers/API/FileController.php` → 0 errors

---

## 4. `UpdateFileRequest` + `DeleteFileRequest` → §3.4, 3.5, C4

- [ ] **4.1** `UpdateFileRequest::authorize()` : `use ChecksFileAuthorization;` + appel `canModerateFile()` (§3.4)
- [ ] **4.2** `DeleteFileRequest::authorize()` : idem (§3.5)
- [ ] **4.3** Cleanup imports inutilisés (notamment `auth()->user()` patterns)
- [ ] **4.4** `php -l` sur les 2 fichiers → 0 errors

---

## 5. Validation no-isAdmin → C5

- [ ] **5.1** `grep -n "isAdmin" app/Http/Controllers/API/FileController.php app/Http/Requests/UpdateFileRequest.php app/Http/Requests/DeleteFileRequest.php`
- [ ] **5.2** Vérifier que les appels résiduels (s'il y en a) sont dans des contextes HORS scope (visibility gate, pas access control)

---

## 6. PHPStan validation → C7

- [ ] **6.1** `vendor/bin/phpstan analyse --memory-limit=512M` → 0 errors hors baseline
- [ ] **6.2** Si baseline change : `composer phpstan:baseline` + vérifier delta

---

## 7. Tests unitaires trait → §4.1, C6

- [ ] **7.1** Créer `tests/Unit/Http/Requests/Concerns/ChecksFileAuthorizationTest.php`
- [ ] **7.2** Classe anonyme `use ChecksFileAuthorization { canReadFile as public; canModerateFile as public; }`
- [ ] **7.3** T1-T8 sur `canReadFile()` selon §4.1
- [ ] **7.4** T9-T14 sur `canModerateFile()` selon §4.1
- [ ] **7.5** `vendor/bin/phpunit tests/Unit/Http/Requests/Concerns/ChecksFileAuthorizationTest.php` → 14/14 PASS

---

## 8. Tests Feature HTTP → §4.2, C6

### 8.1 Read tests (`FileReadAuthorizationTest`)

- [ ] **8.1.1** `mkdir -p tests/Feature/Files`
- [ ] **8.1.2** `.gitignore` whitelist `tests/Feature/Files/**`
- [ ] **8.1.3** Skip `pdo_pgsql` AVANT `parent::setUp()`
- [ ] **8.1.4** 7 cas matrix : owner intra / admin intra / student public intra / student non-public intra / admin cross / supradmin cross / 404 if not found

### 8.2 Stats tests (`FileStatsAuthorizationTest`)

- [ ] **8.2.1** 4 cas : coord intra / student intra (user-scoped) / supradmin / cache isolation

### 8.3 Moderate tests (`FileModerateAuthorizationTest`)

- [ ] **8.3.1** 5 cas (update + destroy similaires) : owner / admin / student public / admin cross / supradmin

### 8.4 Validation

- [ ] **8.4.1** `vendor/bin/phpunit tests/Feature/Files/` → tous skipped local (pdo_pgsql)
- [ ] **8.4.2** Validation : ~15 tests total

---

## 9. Documentation → C10

- [ ] **9.1** Mettre à jour `docs/SECURITY_CI.md` avec entrée fix #102

---

## 10. Audits (CONTRIBUTING.md §A) → C8

- [ ] **10.1** spec-security strict — verdict attendu PASS strict (objectif du PR)
- [ ] **10.2** spec-architect — pattern trait, file size, DI
- [ ] **10.3** spec-reviewer — 15 questions §4
- [ ] **10.4** Si FAIL → corriger
- [ ] **10.5** Présenter les 3 verdicts au user

---

## 11. Commit + PR → C9

- [ ] **11.1** `git add` sélectif (8+ fichiers attendus)
- [ ] **11.2** NE PAS ajouter les untracked non-liés
- [ ] **11.3** Commit Conventional Commits :
  ```
  fix(security): FileController IDOR cross-tenant on show/download/update/destroy + stats leak (closes #102)
  ```
- [ ] **11.4** `git push -u origin fix/file-idor-cross-tenant`
- [ ] **11.5** `gh pr create --base lms`
- [ ] **11.6** `gh pr merge --auto --squash --delete-branch` (pattern utilisateur)

---

## 12. Definition of Done

- [ ] Trait + 14 tests unitaires PASS
- [ ] 5 méthodes Files migrées
- [ ] ~15 tests Feature HTTP (skip local, CI exécutera)
- [ ] Aucun `isAdmin()` résiduel dans le diff
- [ ] PHPStan 0 errors hors baseline
- [ ] 3 audits PASS (security strict obligatoire)
- [ ] PR mergée + Issue #102 fermée automatiquement
