# Tasks — NotificationsController cross-tenant fix

**Issue GitHub** : [#98](https://github.com/ouedraogoissouf2012/lms_backend/issues/98)
**Branche** : `fix/notifications-cross-tenant`
**Spec docs** : [requirements.md](requirements.md) · [design.md](design.md)

---

## 1. Préparation (déjà fait)

- [x] **1.1** Branche `fix/notifications-cross-tenant` créée depuis `lms` à jour
- [x] **1.2** Spec workflow démarré (requirements + design approuvés)
- [x] **1.3** Investigation : colonne `institution_id` existe sur table `notifications` (migration 2026_02_11_000002)
- [x] **1.4** Décision R1 : Option A (ajouter `supradmin` au middleware route)

---

## 2. `CreateNotificationRequest::authorize()` → REQ-1, REQ-2, C1

- [ ] **2.1** Lire le fichier `app/Http/Requests/CreateNotificationRequest.php`
- [ ] **2.2** Réécrire `authorize()` selon §2.1 du design :
  - Role check incluant `supradmin`
  - Bypass `supradmin` court-circuit
  - Tenant check via `User::find($this->input('user_id'))` + comparaison `institution_id`
  - Null checks défensifs (`!is_numeric`, `$targetUser === null`)
- [ ] **2.3** Mettre à jour le PHPDoc de la classe pour expliquer le pattern tenant
- [ ] **2.4** `php -l app/Http/Requests/CreateNotificationRequest.php` → 0 errors

---

## 3. `NotificationsController::stats()` → REQ-4, REQ-5, REQ-6, C2

- [ ] **3.1** Lire le fichier `app/Http/Controllers/API/NotificationsController.php`
- [ ] **3.2** Réécrire `stats()` selon §2.2 du design :
  - Signature `stats(Request $request)` (autowiring)
  - `$user = $this->authenticatedUser($request)` (helper déjà en place via Batch 6)
  - `$isSupradmin = $user->role === 'supradmin'`
  - Cache key incluant `$isSupradmin ? 'global' : "inst_{$user->institution_id}"`
  - Closure `$applyTenantFilter` réutilisée sur 5 queries (total, unread, last24h, last7days, byType)
- [ ] **3.3** PHPDoc mise à jour
- [ ] **3.4** `php -l` → 0 errors

---

## 4. `routes/api.php` → REQ-7, C3

- [ ] **4.1** Modifier la ligne 735 :
  - Avant : `Route::middleware(['auth:sanctum', 'role:coordinateur,superAdmin'])`
  - Après : `Route::middleware(['auth:sanctum', 'role:coordinateur,superAdmin,supradmin'])`
- [ ] **4.2** `php artisan route:list --path=admin/notifications` → 2 routes visibles inchangées

---

## 5. Validation PHPStan → C6

- [ ] **5.1** `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` → 0 errors hors baseline
- [ ] **5.2** Si nouvelles violations introduites : `composer phpstan:baseline` + vérifier delta
- [ ] **5.3** Vérifier aucun appel `User::isAdmin()` dans le diff (C4)

---

## 6. Tests d'intégration HTTP → REQ-8, C5

### 6.1 `CreateNotificationAuthorizationTest`

- [ ] **6.1.1** Créer `tests/Feature/Notifications/CreateNotificationAuthorizationTest.php`
- [ ] **6.1.2** Skip `pdo_pgsql` AVANT `parent::setUp()` (pattern Forum/Quiz)
- [ ] **6.1.3** Setup : 2 institutions A et B
- [ ] **6.1.4** T1 — coordinateur intra-tenant + target intra → 200
- [ ] **6.1.5** T2 — coordinateur intra-tenant + target cross-tenant → 403
- [ ] **6.1.6** T3 — superAdmin intra-tenant + target intra → 200
- [ ] **6.1.7** T4 — superAdmin intra-tenant + target cross-tenant → 403
- [ ] **6.1.8** T5 — supradmin + target intra (n'importe quel tenant) → 200

### 6.2 `NotificationStatsAuthorizationTest`

- [ ] **6.2.1** Créer `tests/Feature/Notifications/NotificationStatsAuthorizationTest.php`
- [ ] **6.2.2** Skip `pdo_pgsql` AVANT `parent::setUp()`
- [ ] **6.2.3** Setup : 2 institutions A et B + créer ≥3 notifs dans chaque institution
- [ ] **6.2.4** T1 — coordinateur intra A → `total` ne compte que les notifs de A
- [ ] **6.2.5** T2 — superAdmin intra A → `total` ne compte que les notifs de A
- [ ] **6.2.6** T3 — supradmin → `total` compte toutes les notifs (A + B)
- [ ] **6.2.7** T4 — cache isolation : appel par coord A puis coord B → résultats distincts (pas de fuite)

### 6.3 Validation tests

- [ ] **6.3.1** `vendor/bin/phpunit tests/Feature/Notifications/` → 9 cas skipped localement (pdo_pgsql absent)
- [ ] **6.3.2** `.gitignore` whitelist `tests/Feature/Notifications/**`

---

## 7. Audits (CONTRIBUTING.md §A) → C7

- [ ] **7.1** Lancer **spec-security** — verdict attendu PASS strict (cible du PR)
- [ ] **7.2** Lancer **spec-architect** — vérifier modifications propres, pas de dette ajoutée
- [ ] **7.3** Lancer **spec-reviewer** — 15 questions §4
- [ ] **7.4** Si FAIL → corriger en revenant à la section appropriée
- [ ] **7.5** Présenter les 3 verdicts au user

---

## 8. Commit & PR → C8

- [ ] **8.1** `git add` sélectif :
  - `app/Http/Requests/CreateNotificationRequest.php`
  - `app/Http/Controllers/API/NotificationsController.php`
  - `routes/api.php`
  - `tests/Feature/Notifications/CreateNotificationAuthorizationTest.php` (nouveau)
  - `tests/Feature/Notifications/NotificationStatsAuthorizationTest.php` (nouveau)
  - `.gitignore` (whitelist)
  - éventuellement `phpstan-baseline.neon`
- [ ] **8.2** NE PAS ajouter les untracked non-liés (`.claude/settings.json`, `docs/INSTALLATION_SERVEUR.md`, `docs/INTEGRATION_KLASSCI.md`)
- [ ] **8.3** Commit Conventional Commits :
  ```
  fix(security): NotificationsController cross-tenant on create + stats (closes #98)
  ```
- [ ] **8.4** Body avec audits + REQs + scope
- [ ] **8.5** `git push -u origin fix/notifications-cross-tenant`
- [ ] **8.6** `gh pr create --base lms` avec body documentant audits + scope
- [ ] **8.7** Si user déléguant le merge (cas précédent) : `gh pr merge --squash --delete-branch`

---

## 9. Definition of Done

- [ ] `CreateNotificationRequest::authorize()` : tenant check + supradmin bypass
- [ ] `NotificationsController::stats()` : filtre tenant + cache scoped + supradmin bypass
- [ ] `routes/api.php` L735 : `supradmin` ajouté au `role:`
- [ ] Aucun `User::isAdmin()` dans le diff
- [ ] 9 tests Feature HTTP créés (skip local, CI exécutera)
- [ ] PHPStan 0 errors hors baseline
- [ ] 3 audits PASS (security strict obligatoire)
- [ ] PR ouverte avec `closes #98`
- [ ] User validé pour merge
- [ ] Issue #98 fermée automatiquement au merge
