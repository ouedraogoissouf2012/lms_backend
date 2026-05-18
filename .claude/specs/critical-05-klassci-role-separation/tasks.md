# CRITICAL-05 — Tasks

> Spec parent : [`requirements.md`](./requirements.md), [`design.md`](./design.md). Issue : [#34](https://github.com/ouedraogoissouf2012/lms_backend/issues/34).

## Stratégie de découpage

**1 seule PR** ciblée sur la racine du problème. Scope ~540 lignes (dont ~430 tests). Précédents cohérents : Forum IDOR #95, Quiz IDOR #96, Notifications #100, File IDOR #103 ont tous été des fixes sécurité mono-PR. Découper CRITICAL-05 en plusieurs PRs serait artificiel et créerait une fenêtre de vulnérabilité entre les merges.

**Ordre d'exécution** : strictement séquentiel. Chaque tâche est validée localement avant de passer à la suivante. Audits `spec-security` + `spec-architect` lancés **en parallèle** après les tâches 1-4 (code applicatif). `spec-reviewer` lancé avant le push, après les tests.

## Tâches

- [ ] **1. Migration de schéma : ajout colonne `klassci_role` + backfill**
  - [ ] 1.1 Créer `database/migrations/2026_05_18_xxxxxx_add_klassci_role_to_users_table.php` : `Schema::table('users', ...)` ajoute `klassci_role string(50) nullable` après `role` + index, et le `down()` drop la colonne et l'index proprement. _Requirements: REQ-2_
  - [ ] 1.2 Dans le `up()`, après le `Schema::table`, exécuter le backfill `DB::table('users')->whereNotNull('klassci_id')->update(['klassci_role' => DB::raw('role')])`. Utilisateurs sans `klassci_id` (seeders supradmin, comptes service) restent `klassci_role = NULL`. _Requirements: REQ-2_
  - [ ] 1.3 Exécuter `php artisan migrate` localement (PostgreSQL via Docker si nécessaire), vérifier la colonne existe et est peuplée pour les users KLASSCI ; tester `php artisan migrate:rollback` pour valider le `down()`. _Requirements: REQ-2_

- [ ] **2. Model `User` : enregistrer la nouvelle colonne**
  - [ ] 2.1 Ajouter `'klassci_role'` au tableau `$fillable` (entre `'role'` et `'klassci_token_encrypted'`). _Requirements: REQ-2_
  - [ ] 2.2 Ajouter `@property string|null $klassci_role` au PHPDoc de classe avec la note « Ne JAMAIS utiliser pour autorisation — utiliser `role` ». _Requirements: REQ-1, REQ-2_
  - [ ] 2.3 Vérifier qu'aucune méthode `isXxx()` n'est ajoutée (anti-pattern : tenter `isKlassciAdmin()` créerait la confusion qu'on veut éviter). _Requirements: REQ-1_

- [ ] **3. Middleware `EnsureKlassciSync` : refactor du `update()` + détection divergence**
  - [ ] 3.1 Extraire la condition « rôle KLASSCI différent du `role` LMS courant » dans une méthode privée `detectAndLogRoleDivergence(User $user, array $klassciUser): void`. Inclut le `Log::warning('klassci_role_divergence_detected', ...)` avec les 6 champs (user_id, institution_id, lms_role, klassci_role_received, klassci_role_previous, is_escalation_attempt). _Requirements: REQ-5_
  - [ ] 3.2 Ajouter la méthode privée `isEscalationAttempt(?string $lmsRole, ?string $klassciRole): bool` qui implémente la hiérarchie de permissivité (cf. design §5.2). Constante de classe pour la hiérarchie. _Requirements: REQ-5_
  - [ ] 3.3 Refactorer l'appel `$user->update([...])` (ligne 59-65 du fichier actuel) pour ne plus inclure ni `'role'` ni `'email'`. Ajouter `'klassci_role' => $klassciUser['role'] ?? $user->klassci_role`. Ajouter un commentaire WHY au-dessus expliquant l'asymétrie REQ-4 (« sécurité : re-sync passif ne doit pas pouvoir modifier `role` ni `email` »). _Requirements: REQ-1, REQ-4_
  - [ ] 3.4 Appeler `$this->detectAndLogRoleDivergence($user, $klassciUser)` AVANT le `update()` (pour pouvoir comparer la valeur courante avant qu'elle ne change). _Requirements: REQ-5_

- [ ] **4. Controller `AuthController::syncUserFromKlassci()` : séparer CREATE vs UPDATE**
  - [ ] 4.1 Identifier la branche `if ($user) { $user->update($userData); } else { ... User::create($userData); }` (lignes ~420-433). _Requirements: REQ-3_
  - [ ] 4.2 Dans la branche UPDATE, retirer `'role'` de `$userData` AVANT le `update()` : `Arr::except($userData, ['role'])` ou copie explicite des champs autorisés. Ajouter `'klassci_role' => $klassciUser['role'] ?? null`. Ajouter commentaire WHY (« sécurité : login d'un user existant ne doit pas pouvoir modifier `role` LMS — la racine d'autorisation reste local »). _Requirements: REQ-3_
  - [ ] 4.3 Dans la branche CREATE, ajouter explicitement `'klassci_role' => $klassciUser['role'] ?? 'etudiant'` (même valeur que `role` à l'initialisation, REQ-3). _Requirements: REQ-3_
  - [ ] 4.4 Garder `'email'` dans la branche UPDATE (asymétrie documentée design §4 : email peut changer au login, mais pas au re-sync passif). _Requirements: REQ-4_

- [ ] **5. Tests unitaires middleware**
  - [ ] 5.1 Créer `tests/Unit/Middleware/EnsureKlassciSyncTest.php`. Setup : `RefreshDatabase`, mock `KlassciProxyService` via Mockery, instancier le middleware, créer un user de test. _Requirements: REQ-6_
  - [ ] 5.2 Implémenter les 6 tests middleware listés en REQ-6 : `test_resync_does_not_overwrite_role`, `test_resync_updates_klassci_role`, `test_resync_does_not_overwrite_email`, `test_resync_updates_name_and_klassci_data`, `test_resync_logs_warning_on_role_divergence`, `test_resync_does_not_log_when_roles_match`. _Requirements: REQ-1, REQ-4, REQ-5, REQ-6_
  - [ ] 5.3 Ajouter `test_klassci_api_failure_does_not_overwrite_role` (mock `KlassciProxyService::get()` throws Exception, asserter user inchangé en DB). _Requirements: REQ-4, REQ-6_
  - [ ] 5.4 Ajouter `test_resync_logs_is_escalation_attempt_true_only_when_role_more_permissive` (LMS=etudiant, KLASSCI=supradmin → flag true ; LMS=enseignant, KLASSCI=etudiant → flag false). _Requirements: REQ-5_

- [ ] **6. Tests Feature multi-tenant et bout-en-bout**
  - [ ] 6.1 Créer `tests/Feature/Security/KlassciRoleSeparationTest.php` avec `RefreshDatabase` + `Sanctum::actingAs`. _Requirements: REQ-6_
  - [ ] 6.2 Implémenter `test_initial_sync_initializes_both_roles` : appel `POST /api/auth/login`, mock KLASSCI réponse, asserter `role === klassci_role === 'etudiant'`. _Requirements: REQ-3, REQ-6_
  - [ ] 6.3 Implémenter `test_initial_sync_preserves_lms_role_when_user_exists` : user pré-existant avec `role=enseignant`, login retour KLASSCI `role=etudiant`, asserter `role=enseignant` ET `klassci_role=etudiant`. _Requirements: REQ-3, REQ-6_
  - [ ] 6.4 Implémenter `test_multi_tenant_isolation` : 2 users, institutions différentes ; re-sync user A modifie `klassci_role` du user A uniquement, user B intact (vérification par 2× `User::find($id)`). _Requirements: REQ-6, REQ-7_

- [ ] **7. Audits read-only (parallèles)**
  - [ ] 7.1 Lancer `spec-security` agent en parallèle de `spec-architect` agent sur le diff complet. Cible : 0 finding HIGH/CRITICAL. Si rapport négatif, corriger AVANT d'aller en 7.2. _Requirements: critère d'acceptation §5 (spec-security 0 HIGH/CRITICAL) + §6 (spec-architect 0 HIGH/CRITICAL)_
  - [ ] 7.2 Lancer `spec-reviewer` qui consomme les 2 rapports précédents + applique les 15 questions du manifeste. Verdict cible : MERGE-READY. _Requirements: critère d'acceptation §7_

- [ ] **8. Validation locale complète**
  - [ ] 8.1 `vendor/bin/phpstan analyse --no-progress` → `[OK] No errors`. Si baseline gonfle, investiguer (pas régénérer aveuglément). _Requirements: critère d'acceptation §2_
  - [ ] 8.2 `vendor/bin/phpunit tests/Unit/Middleware/EnsureKlassciSyncTest.php` → 8 tests verts. _Requirements: REQ-6_
  - [ ] 8.3 `vendor/bin/phpunit tests/Feature/Security/KlassciRoleSeparationTest.php` → 4 tests verts (ou skipped CI-only si pas de `pdo_pgsql` local). _Requirements: REQ-6_
  - [ ] 8.4 `vendor/bin/phpunit tests/Feature/LMS` → 50 tests existants restent verts (régression check). _Requirements: REQ-7_
  - [ ] 8.5 `php artisan migrate:fresh --seed` puis `php artisan migrate:rollback` localement → tous deux OK. _Requirements: REQ-2, critère d'acceptation §4_

- [ ] **9. Commit + push + PR**
  - [ ] 9.1 Présenter le récap des changements à l'utilisateur AVANT `git commit` (rule `feedback_no_commit_without_approval`).
  - [ ] 9.2 Sur approbation explicite, créer 1 commit Conventional Commit type `fix(security)` avec mention `closes #34` dans le body. _Requirements: critère d'acceptation §8_
  - [ ] 9.3 `git push -u origin fix/critical-05-klassci-role-separation`.
  - [ ] 9.4 `gh pr create --base lms --title "fix(security): separate klassci_role from role to prevent KLASSCI sync privilege escalation (closes #34)"` avec body détaillé : scénario d'attaque, REQs couverts, tests passants, audits OK.
  - [ ] 9.5 Attendre que l'utilisateur merge la PR côté GitHub. Pas de `gh pr merge --auto` (rule `feedback_no_commit_without_approval`).

## Récap mapping `_Requirements_`

| REQ | Tasks |
|---|---|
| REQ-1 (autorisation `role` LMS uniquement) | 2.2, 2.3, 3.3, 5.2 |
| REQ-2 (colonne `klassci_role` + backfill) | 1.1, 1.2, 1.3, 2.1, 2.2, 8.5 |
| REQ-3 (sign-up CREATE vs UPDATE) | 4.1, 4.2, 4.3, 6.2, 6.3 |
| REQ-4 (re-sync n'écrase plus `role`/`email`) | 3.3, 4.4, 5.2, 5.3 |
| REQ-5 (détection divergence) | 3.1, 3.2, 3.4, 5.2, 5.4 |
| REQ-6 (tests obligatoires) | 5.1-5.4, 6.1-6.4, 8.2, 8.3 |
| REQ-7 (régression check) | 6.4, 8.4 |

## Estimation et risques

- **Temps estimé** : ~3-4h en exécution séquentielle locale, sans interruption.
- **Risque principal** : un consommateur de `$user->klassci_data['role']` (lecture indirecte du payload JSON) pourrait bypasser la nouvelle séparation. **Mitigation** : grep `klassci_data.*role` avant de commit ; aucun consommateur identifié actuellement (cf. discovery).
- **Risque secondaire** : la migration backfill `whereNotNull('klassci_id')` doit s'exécuter dans une transaction si la table est volumineuse. À 20k users actuels, scalable ; au-delà de 200k, batcher. Pas un risque court terme (cf. design §10).
