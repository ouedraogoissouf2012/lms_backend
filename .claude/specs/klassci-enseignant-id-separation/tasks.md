# klassci_enseignant_id separation — Tasks

> Spec parent : [`requirements.md`](./requirements.md), [`design.md`](./design.md). Issue : [#119](https://github.com/ouedraogoissouf2012/lms_backend/issues/119).

## Stratégie de découpage

**1 seule PR** ciblée sur la racine du problème. Scope ~490 lignes (dont ~420 tests). Précédent cohérent : PR #118 (CRITICAL-05) — fix sécurité mono-PR avec spec complète. Découper en plusieurs PRs créerait une fenêtre de vulnérabilité entre les merges.

**Ordre d'exécution** : strictement séquentiel. Audits `spec-security` + `spec-architect` lancés **en parallèle** après les tâches 1-5 (code applicatif). `spec-reviewer` lancé avant le push, après les tests.

## Tâches

- [ ] **1. Migration de schéma : ajout colonne `klassci_enseignant_id` + backfill**
  - [ ] 1.1 Créer `database/migrations/2026_05_18_000002_add_klassci_enseignant_id_to_users_table.php`. `up()` : guard `Schema::hasColumn('users', 'klassci_enseignant_id')` (idempotent), puis `Schema::table` ajoute `unsignedBigInteger('klassci_enseignant_id')->nullable()->after('klassci_role')` + index. `down()` : drop index + drop column. _Requirements: REQ-2_
  - [ ] 1.2 Dans le `up()`, après le `Schema::table`, exécuter le backfill par `DB::table('users')->whereNotNull('klassci_id')->orderBy('id')->chunkById(1000, function ($users) { ... foreach decode klassci_data, extract enseignant_id, UPDATE })`. _Requirements: REQ-2_
  - [ ] 1.3 Exécuter `php artisan migrate` localement, vérifier la colonne existe et est peuplée. Tester `php artisan migrate:rollback --step=1` puis re-migrate pour valider l'idempotence du `up()` et la propreté du `down()`. _Requirements: REQ-2_

- [ ] **2. Model `User` : enregistrer la nouvelle colonne + commentaire de garde**
  - [ ] 2.1 Ajouter `'klassci_enseignant_id'` au tableau `$fillable` (immédiatement après `'klassci_role'`). _Requirements: REQ-6_
  - [ ] 2.2 Ajouter `@property int|null $klassci_enseignant_id` au PHPDoc de classe avec note « Initialisé au sign-up KLASSCI ; jamais réécrit par re-sync. Source d'autorité unique pour les checks d'ownership enseignant. Ne JAMAIS lire `klassci_data['enseignant_id']` pour l'autorisation. ». _Requirements: REQ-1, REQ-6_
  - [ ] 2.3 Ajouter un **commentaire de garde** au-dessus de la déclaration des `getKlassciDataAttribute`/`setKlassciDataAttribute` mutators : « Ce blob est un cache display informationnel — il est écrasable au re-sync. NE PAS lire `klassci_data['XXX_id']` pour de l'autorisation. Pour les champs d'autorité, utiliser leurs colonnes dédiées (`role`, `klassci_role`, `klassci_enseignant_id`). ». _Requirements: design §4, §11 risque résiduel_

- [ ] **3. Controller `AuthController::syncUserFromKlassci()` : write-once au CREATE**
  - [ ] 3.1 Localiser la branche `if ($user) { update } else { create }` (~ lignes 421-441 post-PR #118). _Requirements: REQ-3_
  - [ ] 3.2 Dans la branche CREATE (`$user = User::withoutGlobalScope('institution')->create(array_merge($commonData, [...]))`), ajouter `'klassci_enseignant_id' => $klassciUser['enseignant_id'] ?? null` au tableau passé à `array_merge`. Ajouter commentaire WHY (« sécurité — write-once : seul moment où l'identité enseignant KLASSCI est inscrite »). _Requirements: REQ-3_
  - [ ] 3.3 Vérifier que `$commonData` n'inclut PAS `klassci_enseignant_id` (sinon la branche UPDATE l'écrasera). Si jamais présent, le retirer. _Requirements: REQ-3_

- [ ] **4. Middleware `EnsureKlassciSync` : verification no-op**
  - [ ] 4.1 Vérifier que le `$user->update([...])` du middleware (post-PR #118) ne contient PAS `klassci_enseignant_id`. Aucun changement code attendu — c'est déjà le cas par défaut. _Requirements: REQ-4_
  - [ ] 4.2 Ajouter un commentaire au-dessus du `$user->update([...])` listant explicitement les champs **exclus** : « `role` (CRITICAL-05), `email` (CRITICAL-05), `klassci_enseignant_id` (#119) ne sont JAMAIS écrits ici ». _Requirements: REQ-4, défense en profondeur lecture humaine_

- [ ] **5. Migration des 3 FormRequests vulnérables**
  - [ ] 5.1 [`app/Http/Requests/DeleteEvaluationRequest.php`](app/Http/Requests/DeleteEvaluationRequest.php) : remplacer ligne 45 `data_get($user->klassci_data, 'enseignant_id')` par `$user->klassci_enseignant_id`. Ajouter le guard nullable : `if ($userKlassciEnseignantId === null || $evaluation->klassci_enseignant_id !== $userKlassciEnseignantId) { return false; }`. Ajouter commentaire WHY (« CRITICAL #119 : lecture depuis la colonne dédiée write-once, pas le blob klassci_data »). _Requirements: REQ-1, REQ-5_
  - [ ] 5.2 [`app/Http/Requests/PublishEvaluationRequest.php`](app/Http/Requests/PublishEvaluationRequest.php) ligne 45 : appliquer le même patch verbatim. _Requirements: REQ-1, REQ-5_
  - [ ] 5.3 [`app/Http/Requests/UpdateEvaluationRequest.php`](app/Http/Requests/UpdateEvaluationRequest.php) ligne 50 : appliquer le même patch verbatim. _Requirements: REQ-1, REQ-5_

- [ ] **6. Tests Unit — middleware re-sync invariant**
  - [ ] 6.1 Dans `tests/Unit/Middleware/EnsureKlassciSyncTest.php` (existant), ajouter helper `staleUserWithEnseignantId(int $enseignantId): User` (similaire à `staleUser`). _Requirements: REQ-7 #3_
  - [ ] 6.2 Ajouter `test_resync_does_not_overwrite_klassci_enseignant_id` : user `klassci_enseignant_id=42`, mock KLASSCI renvoie `enseignant_id=999`, asserter `user->klassci_enseignant_id === 42` après middleware. _Requirements: REQ-4, REQ-7 #3_

- [ ] **7. Tests Feature — bout-en-bout, 3 FormRequests, multi-tenant**
  - [ ] 7.1 Créer `tests/Feature/Security/KlassciEnseignantIdSeparationTest.php` avec `RefreshDatabase` + `Sanctum::actingAs` + helper `mockKlassciAuthMe` + helper `callSyncUserFromKlassci` (réflexion, identique au pattern PR #118). _Requirements: REQ-7_
  - [ ] 7.2 Implémenter `test_create_initializes_klassci_enseignant_id_from_payload` : `syncUserFromKlassci` avec `enseignant_id=42` sur user inexistant, asserter `user->klassci_enseignant_id === 42`. _Requirements: REQ-3, REQ-7 #1_
  - [ ] 7.3 Implémenter `test_update_does_not_overwrite_klassci_enseignant_id` : user existant `klassci_enseignant_id=42`, `syncUserFromKlassci` avec payload `enseignant_id=999`, asserter `user->fresh()->klassci_enseignant_id === 42`. _Requirements: REQ-3, REQ-7 #2_
  - [ ] 7.4 Implémenter `test_delete_evaluation_authorized_for_owner_via_dedicated_column` : user `klassci_enseignant_id=42`, eval `klassci_enseignant_id=42`, `DELETE /api/evaluations/{id}` → 200. _Requirements: REQ-5, REQ-7 #4_
  - [ ] 7.5 Implémenter `test_delete_evaluation_blocked_for_klassci_data_blob_attacker` : user `klassci_enseignant_id=42` mais `klassci_data['enseignant_id']=999` (blob écrasé), eval `klassci_enseignant_id=999`, `DELETE` → 403 (preuve que le FormRequest ignore le blob). _Requirements: REQ-1, REQ-5, REQ-7 #5_
  - [ ] 7.6 Implémenter `test_publish_evaluation_blocked_for_klassci_data_blob_attacker` : idem sur `POST /api/evaluations/{id}/publish`. _Requirements: REQ-1, REQ-5, REQ-7 #6_
  - [ ] 7.7 Implémenter `test_update_evaluation_blocked_for_klassci_data_blob_attacker` : idem sur `PUT /api/evaluations/{id}`. _Requirements: REQ-1, REQ-5, REQ-7 #7_
  - [ ] 7.8 Implémenter `test_user_without_klassci_enseignant_id_cannot_delete_evaluation` : user `klassci_enseignant_id=NULL`, eval random, `DELETE` → 403. _Requirements: REQ-5, REQ-7 #9_
  - [ ] 7.9 Implémenter `test_admin_can_delete_evaluation_regardless_of_klassci_enseignant_id` : user `role=supradmin`, `klassci_enseignant_id=NULL`, eval random, `DELETE` → 200 (bypass admin existant préservé). _Requirements: REQ-5, REQ-7 #10_

- [ ] **8. Test Feature — backfill migration**
  - [ ] 8.1 Créer `tests/Feature/Security/KlassciEnseignantIdBackfillTest.php` avec `RefreshDatabase`. _Requirements: REQ-2, REQ-7 #8_
  - [ ] 8.2 Implémenter `test_migration_backfill_copies_enseignant_id_from_blob` : approche — créer user avec `klassci_enseignant_id` mis explicitement à NULL et `klassci_data` contenant `'enseignant_id' => 42`, exécuter `Artisan::call('migrate:refresh')` puis re-créer le user dans le même état, puis appeler manuellement la closure de backfill (extraire la logique de la migration via `DB::table('users')->update(...)` pour les rows ayant `klassci_enseignant_id NULL` mais `klassci_data->>enseignant_id` populated). Asserter colonne populated. _Requirements: REQ-2, REQ-7 #8_

- [ ] **9. Audits read-only (parallèles)**
  - [ ] 9.1 Lancer `spec-security` + `spec-architect` en parallèle sur le diff complet. Cible : 0 finding HIGH/CRITICAL. Si rapport négatif, corriger AVANT d'aller en 9.2. _Requirements: critère d'acceptation §5 + §6_
  - [ ] 9.2 Lancer `spec-reviewer` qui consomme les 2 rapports précédents + applique les 15 questions du manifeste. Verdict cible : MERGE-READY. _Requirements: critère d'acceptation §7_

- [ ] **10. Validation locale complète**
  - [ ] 10.1 `vendor/bin/phpstan analyse --no-progress --memory-limit=2G` → `[OK] No errors`. Si baseline gonfle, investiguer. _Requirements: critère d'acceptation §2_
  - [ ] 10.2 `vendor/bin/phpunit tests/Unit/Middleware/EnsureKlassciSyncTest.php` → 10 tests verts (9 existants PR #118 + 1 nouveau resync-no-overwrite-enseignant-id). _Requirements: REQ-7_
  - [ ] 10.3 `vendor/bin/phpunit tests/Feature/Security/` → 13 tests verts (4 PR #118 + 9 nouveaux Eval + 1 backfill = 14, dont skip CI-only si pas de `pdo_pgsql` local). _Requirements: REQ-7_
  - [ ] 10.4 `vendor/bin/phpunit tests/Feature/Quiz tests/Feature/Forum tests/Feature/Notifications tests/Feature/LMS` → suites existantes intactes (régression check). _Requirements: REQ-8_
  - [ ] 10.5 `php artisan migrate:rollback --step=1` puis `php artisan migrate` localement → tous deux OK. _Requirements: REQ-2_

- [ ] **11. Commit + push + PR**
  - [ ] 11.1 Présenter le récap des changements à l'utilisateur AVANT `git commit` (rule `feedback_no_commit_without_approval`).
  - [ ] 11.2 Sur approbation explicite, créer 1 commit Conventional Commit type `fix(security)` avec mention `closes #119` dans le body. _Requirements: critère d'acceptation §8_
  - [ ] 11.3 `git push -u origin fix/critical-119-klassci-enseignant-id-separation`.
  - [ ] 11.4 `gh pr create --base lms --title "fix(security): separate users.klassci_enseignant_id from klassci_data blob to prevent IDOR (closes #119)"` avec body-file détaillé : scénario d'attaque, REQs couverts, tests passants, audits OK.
  - [ ] 11.5 Attendre que l'utilisateur merge la PR côté GitHub. Pas de `gh pr merge --auto`.
  - [ ] 11.6 Post-merge : fermer manuellement l'issue #119 avec `gh issue close 119 -c "..."` (la branche `lms` n'est pas la default donc `closes #119` ne s'auto-ferme pas).

## Récap mapping `_Requirements_`

| REQ | Tasks |
|---|---|
| REQ-1 (source de vérité unique) | 2.2, 5.1, 5.2, 5.3, 7.5, 7.6, 7.7 |
| REQ-2 (migration + backfill) | 1.1, 1.2, 1.3, 8.1, 8.2, 10.5 |
| REQ-3 (CREATE write-once) | 3.1, 3.2, 3.3, 7.2, 7.3 |
| REQ-4 (re-sync no-write) | 4.1, 4.2, 6.1, 6.2 |
| REQ-5 (3 FormRequests migrées) | 5.1, 5.2, 5.3, 7.4-7.9 |
| REQ-6 (User model) | 2.1, 2.2 |
| REQ-7 (10 tests obligatoires) | 6.1-6.2, 7.1-7.9, 8.1-8.2, 10.2-10.3 |
| REQ-8 (régression check) | 10.4 |

## Estimation et risques

- **Temps estimé** : ~3h en exécution séquentielle locale (précédent #118 : ~3h sur scope comparable).
- **Risque principal** : un consommateur futur de `data_get($user->klassci_data, 'enseignant_id')` pourrait ré-introduire le bug. **Mitigation** : le commentaire de garde dans le PHPDoc de `klassci_data` (tâche 2.3) + tests 7.5/7.6/7.7 qui restent en file d'attente comme regression check.
- **Risque secondaire** : la migration backfill doit s'exécuter dans une transaction si la table est volumineuse. Le `chunkById(1000)` mitige déjà ce risque. Acceptable à 20k users actuels, scalable à 200k (cf. design §10).
- **Risque tertiaire** : aucun test feature actuel ne couvre les 3 FormRequests sur le scénario attaquant (`data_get` au lieu de la colonne). Tâche 7.5/7.6/7.7 ferment ce gap.
