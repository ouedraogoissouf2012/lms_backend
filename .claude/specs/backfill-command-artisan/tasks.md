# Backfill command artisan — Tasks

> Spec parent : [`requirements.md`](./requirements.md), [`design.md`](./design.md). Issue : [#126](https://github.com/ouedraogoissouf2012/lms_backend/issues/126).

## Stratégie de découpage

**1 seule PR** chirurgicale, scope ~515 LOC strictement additif (zéro modification de logique métier existante). Précédent cohérent : pattern de création de tooling ops (commande artisan + tests Feature dédiés).

**Ordre d'exécution** strictement séquentiel.

## Tâches

- [ ] **1. Créer le command `BackfillRoleCommand`**
  - [ ] 1.1 Créer le sous-dossier `app/Console/Commands/Klassci/` (nouveau sous-dir cohérent avec `app/Services/Klassci*`). _Requirements: REQ-1_
  - [ ] 1.2 Créer `app/Console/Commands/Klassci/BackfillRoleCommand.php` selon design §3 :
    - `<?php declare(strict_types=1);`
    - `namespace App\Console\Commands\Klassci;`
    - `use Illuminate\Console\Command;` + `use Illuminate\Support\Facades\DB;`
    - PHPDoc complet (cf. design §3) référençant issue #126, idempotence, usage, sister command, migration source
    - `final class BackfillRoleCommand extends Command`
    - Signature : `klassci:backfill-role {--chunk=1000} {--dry-run}`
    - Description claire
    - `handle()` : valide chunk size, compte total, progress bar, chunkById, UPDATE batch SQL avec `DB::raw('role')`, retourne `self::SUCCESS`
    - _Requirements: REQ-1, REQ-6_
  - [ ] 1.3 `php -l app/Console/Commands/Klassci/BackfillRoleCommand.php` → No syntax errors. _Requirements: REQ-1_

- [ ] **2. Créer le command `BackfillEnseignantIdCommand`**
  - [ ] 2.1 Créer `app/Console/Commands/Klassci/BackfillEnseignantIdCommand.php` selon design §4 :
    - Boilerplate identique à 1.2
    - PHPDoc complet (cf. design §4) référençant issue #126, idempotence, skips, usage
    - Signature : `klassci:backfill-enseignant-id {--chunk=1000} {--dry-run}`
    - `handle()` : valide chunk, compte total (avec filtre `whereNull('klassci_enseignant_id')`), progress bar, chunkById, foreach PHP avec json_decode + data_get + is_numeric guard, UPDATE row par row, compteurs `$updated` + `$skipped`
    - _Requirements: REQ-2, REQ-6_
  - [ ] 2.2 `php -l app/Console/Commands/Klassci/BackfillEnseignantIdCommand.php` → No syntax errors. _Requirements: REQ-2_

- [ ] **3. Vérifier auto-discovery Laravel des commands**
  - [ ] 3.1 `php artisan list klassci` → afficher les 2 commands `backfill-role` + `backfill-enseignant-id`. Si non, vérifier `app/Console/Kernel.php` ou Laravel 11+ auto-discovery. _Requirements: critère d'acceptation §4_
  - [ ] 3.2 `php artisan klassci:backfill-role --help` → afficher la signature complète + options. _Requirements: REQ-1_
  - [ ] 3.3 `php artisan klassci:backfill-enseignant-id --help` → idem. _Requirements: REQ-2_

- [ ] **4. Modifier les 2 migrations existantes (REQ-3 — commentaires ops uniquement)**
  - [ ] 4.1 Dans `database/migrations/2026_05_18_000001_add_klassci_role_to_users_table.php`, insérer le commentaire ops 5 lignes au-dessus de `DB::table('users')->whereNotNull('klassci_id')` (cf. design §5.1). _Requirements: REQ-3_
  - [ ] 4.2 Dans `database/migrations/2026_05_19_000001_add_klassci_enseignant_id_to_users_table.php`, insérer le commentaire ops similaire pointant vers `klassci:backfill-enseignant-id`. _Requirements: REQ-3_
  - [ ] 4.3 `php -l` sur les 2 migrations → No syntax errors. _Requirements: REQ-3_
  - [ ] 4.4 **AUCUN changement de logique** des migrations — vérifier par `git diff` qu'on a uniquement ajouté des commentaires. _Requirements: REQ-5_

- [ ] **5. Smoke local : dry-run des 2 commands**
  - [ ] 5.1 `php artisan klassci:backfill-role --dry-run --chunk=100` → s'exécute sans erreur, affiche compteur. _Requirements: REQ-1, critère §5_
  - [ ] 5.2 `php artisan klassci:backfill-enseignant-id --dry-run --chunk=100` → s'exécute sans erreur, affiche compteurs `updated` + `skipped`. _Requirements: REQ-2, critère §6_

- [ ] **6. Tests Feature `BackfillRoleCommandTest`**
  - [ ] 6.1 Créer `tests/Feature/Console/BackfillRoleCommandTest.php` :
    - `<?php declare(strict_types=1);`
    - `namespace Tests\Feature\Console;`
    - `extends Tests\TestCase` + `use RefreshDatabase` + skip si `!extension_loaded('pdo_pgsql')`
    - PHPDoc référençant issue #126
    - _Requirements: REQ-4_
  - [ ] 6.2 Implémenter `test_backfill_copies_role_to_klassci_role_for_synced_users` : créer 3 users avec `klassci_id` non null + `klassci_role = null` + `role` variant (`etudiant`/`enseignant`/`coordinateur`). Exec command. Asserter `klassci_role === role` pour les 3. _Requirements: REQ-4 #1_
  - [ ] 6.3 Implémenter `test_backfill_skips_users_without_klassci_id` : user avec `klassci_id = null` (compte supradmin local). Exec command. Asserter `klassci_role === null`. _Requirements: REQ-4 #2_
  - [ ] 6.4 Implémenter `test_backfill_is_idempotent` : créer 2 users synced + run command 2× → 2ᵉ run modifie 0 row (mais retourne SUCCESS), `klassci_role` toujours = `role`. _Requirements: REQ-4 #3_
  - [ ] 6.5 Implémenter `test_dry_run_does_not_write_to_db` : user synced avec `klassci_role = null`. Exec `--dry-run`. Asserter `klassci_role` reste `null` mais compteur affiché. _Requirements: REQ-4 #4_

- [ ] **7. Tests Feature `BackfillEnseignantIdCommandTest`**
  - [ ] 7.1 Créer `tests/Feature/Console/BackfillEnseignantIdCommandTest.php` (mêmes imports/setup que 6.1). _Requirements: REQ-4_
  - [ ] 7.2 Implémenter `test_backfill_extracts_enseignant_id_from_blob` : user avec `klassci_data = '{"enseignant_id": 42, "nom": "Doe"}'` + `klassci_enseignant_id = null`. Exec command. Asserter `klassci_enseignant_id === 42`. _Requirements: REQ-4 #1_
  - [ ] 7.3 Implémenter `test_backfill_skips_users_without_enseignant_id_in_blob` : user étudiant avec `klassci_data = '{"etudiant_id": 99, "nom": "Doe"}'`. Exec command. Asserter `klassci_enseignant_id` reste `null`. _Requirements: REQ-4 #2_
  - [ ] 7.4 Implémenter `test_backfill_is_idempotent` : user synced avec blob `enseignant_id: 42`. Run 1× → backfill effectué. Run 2× → 0 candidat, exit SUCCESS, valeur inchangée. _Requirements: REQ-4 #3_
  - [ ] 7.5 Implémenter `test_dry_run_does_not_write_to_db` : user synced avec blob. Exec `--dry-run`. Asserter `klassci_enseignant_id` reste `null` mais compteur affiché. _Requirements: REQ-4 #4_
  - [ ] 7.6 Implémenter `test_handles_malformed_klassci_data_gracefully` : 3 users (klassci_data = `'invalid json{'`, klassci_data = `null`, klassci_data = `'[]'` array sans clé). Exec command. Asserter pas de crash, tous skippés. _Requirements: REQ-4 #5_

- [ ] **8. PHPStan + lint exhaustif**
  - [ ] 8.1 `php -l` sur les 4 nouveaux fichiers + 2 migrations modifiées. _Requirements: REQ-3_
  - [ ] 8.2 `vendor/bin/phpstan analyse --no-progress --memory-limit=2G` → `[OK] No errors`. _Requirements: critère §2_

- [ ] **9. Régression cross-suite**
  - [ ] 9.1 `vendor/bin/phpunit tests/Feature/Console` → 11 tests nouveaux (5 + 6) chargés. _Requirements: REQ-4_
  - [ ] 9.2 `vendor/bin/phpunit tests/Feature/LMS tests/Feature/Security tests/Unit` → suites pré-existantes intactes. _Requirements: REQ-5_
  - [ ] 9.3 `vendor/bin/phpunit tests/Feature/Security/KlassciEnseignantIdBackfillTest.php` → 2 tests existants (régression spécifique migration backfill) intacts. _Requirements: REQ-5_

- [ ] **10. Audits read-only**
  - [ ] 10.1 Lancer `spec-security` + `spec-architect` en parallèle sur le diff. Cible : 0 finding HIGH/CRITICAL. _Requirements: critères §7 + §8_
  - [ ] 10.2 Corriger les findings éventuels AVANT 10.3.
  - [ ] 10.3 Lancer `spec-reviewer` (15 questions). Verdict cible : MERGE-READY. _Requirements: critère §9_

- [ ] **11. Validation locale finale**
  - [ ] 11.1 `git diff lms..HEAD --stat`. Bilan attendu : ~7 fichiers, +515 LOC net (2 commands + 4 tests + 2 migrations PHPDoc + 3 spec docs). _Requirements: critère §1_
  - [ ] 11.2 `php artisan list klassci` montre les 2 commands. _Requirements: critère §4_

- [ ] **12. Commit + push + PR + fermeture #126**
  - [ ] 12.1 Présenter le récap des changements à l'utilisateur AVANT `git commit`.
  - [ ] 12.2 Sur approbation explicite, créer 1 commit Conventional Commit type `refactor` avec mention `closes #126` dans le body. _Requirements: critère §10_
  - [ ] 12.3 `git push -u origin refactor/126-backfill-command-artisan`.
  - [ ] 12.4 `gh pr create --base lms --title "refactor: extract backfill into klassci:backfill-{role,enseignant-id} artisan commands (closes #126)"` avec body-file détaillé.
  - [ ] 12.5 Attendre que l'utilisateur merge la PR côté GitHub.
  - [ ] 12.6 Post-merge : `gh issue close 126 -c "Résolu par PR #XXX..."`.

## Récap mapping `_Requirements_`

| REQ | Tasks |
|---|---|
| REQ-1 (`klassci:backfill-role` command) | 1.1, 1.2, 1.3, 3.1, 3.2, 5.1 |
| REQ-2 (`klassci:backfill-enseignant-id` command) | 2.1, 2.2, 3.1, 3.3, 5.2 |
| REQ-3 (commentaires ops migrations) | 4.1, 4.2, 4.3, 8.1 |
| REQ-4 (tests Feature 11 tests) | 6.1-6.5, 7.1-7.6, 9.1 |
| REQ-5 (régression migrations existantes) | 4.4, 9.2, 9.3 |
| REQ-6 (documentation commands) | 1.2, 2.1 |

## Estimation et risques

- **Temps estimé** : ~2-3h en exécution séquentielle locale. Tooling ops + tests, pas de complexité algorithmique.
- **Risque principal** : un test Feature qui fait `RefreshDatabase` puis crée des users via factory pour exécuter le command. La factory `UserFactory` produit `klassci_id` non null et `klassci_role` non null par défaut (cf. UserFactory:39-42 post-#118). Pour tester le scénario `klassci_role = null`, il faut **forcer `null`** via factory state ou `User::factory()->create([...])->update(['klassci_role' => null])`. **Mitigation** : tâche 6.2 explicite le pattern.
- **Risque secondaire** : Laravel 11+ auto-discover les commands depuis `app/Console/Commands/**` par défaut. Vérifier dans `bootstrap/app.php` ou `app/Console/Kernel.php`. Si non, déclarer manuellement. **Mitigation** : tâche 3.1 (`artisan list klassci`).
- **Risque tertiaire** : test `test_handles_malformed_klassci_data_gracefully` (7.6) — `json_decode('invalid json', true)` retourne `null`, et le code fait `is_array($blob) ? data_get(...) : null` puis `is_numeric(null) = false`, donc skip. À vérifier dans le test que `User::factory()->create(['klassci_data' => 'invalid json{'])` ne crash pas au setter Eloquent (User model a un mutator `setKlassciDataAttribute` qui appelle `json_encode` si array sinon stocke as-is). String passe direct → OK. **Mitigation** : test explicite avec assertion `expectsNoException`.
