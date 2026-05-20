# ChecksEvaluationOwnership trait — Tasks

> Spec parent : [`requirements.md`](./requirements.md), [`design.md`](./design.md). Issue : [#125](https://github.com/ouedraogoissouf2012/lms_backend/issues/125).

## Stratégie de découpage

**1 seule PR** chirurgicale, refactor pur DRY. Scope ~+205 lignes net (~+75 trait, +220 tests, −90 dans les 3 FormRequests). Précédent cohérent : pattern de refactor (PR #122 SeanceQueryService, AttendanceStatusService).

**Ordre d'exécution** strictement séquentiel. Risque principal : casser le comportement runtime → vérifier par régression Feature suite (#118/#122/#127/#128 = 35 tests Security).

## Tâches

- [ ] **1. Créer le trait `ChecksEvaluationOwnership`**
  - [ ] 1.1 Créer le sous-dossier `app/Http/Requests/Concerns/` (sera vide jusqu'à présent). _Requirements: REQ-1_
  - [ ] 1.2 Créer `app/Http/Requests/Concerns/ChecksEvaluationOwnership.php` avec :
    - `<?php declare(strict_types=1);` + `namespace App\Http\Requests\Concerns;`
    - `use App\Models\Evaluation;` + `use App\Models\User;`
    - PHPDoc complet (cf. design §2.1) référençant issues #125, #119, #124 + les 3 FormRequests consommateurs
    - `trait ChecksEvaluationOwnership { protected function checkEvaluationOwnership(): bool { ... } }`
    - Logique verbatim du pattern actuel (cf. design §2.1)
    - _Requirements: REQ-1, REQ-6_
  - [ ] 1.3 `php -l app/Http/Requests/Concerns/ChecksEvaluationOwnership.php` → No syntax errors. _Requirements: REQ-1_

- [ ] **2. Migrer `DeleteEvaluationRequest`**
  - [ ] 2.1 Ajouter `use App\Http\Requests\Concerns\ChecksEvaluationOwnership;` dans les imports (au-dessus de `use Illuminate\Foundation\Http\FormRequest;`). _Requirements: REQ-2_
  - [ ] 2.2 Ajouter `use ChecksEvaluationOwnership;` immédiatement après la déclaration de classe (avant les méthodes). _Requirements: REQ-2_
  - [ ] 2.3 Remplacer le corps complet de `authorize()` par `return $this->checkEvaluationOwnership();`. _Requirements: REQ-2_
  - [ ] 2.4 Mettre à jour la PHPDoc de classe : conserver `## Purpose` (logique propre à `delete`), retirer la section `## Authorization Model` et la remplacer par `## Authorization\nDelegated to {@see ChecksEvaluationOwnership::checkEvaluationOwnership()}...` (cf. design §3.2). _Requirements: REQ-3_
  - [ ] 2.5 `php -l app/Http/Requests/DeleteEvaluationRequest.php` → No syntax errors. _Requirements: REQ-2_

- [ ] **3. Migrer `PublishEvaluationRequest`**
  - [ ] 3.1 Mêmes étapes que 2.1-2.4 (verbatim — différence seulement dans la PHPDoc `## Purpose`). _Requirements: REQ-2, REQ-3_
  - [ ] 3.2 `php -l app/Http/Requests/PublishEvaluationRequest.php` → No syntax errors. _Requirements: REQ-2_

- [ ] **4. Migrer `UpdateEvaluationRequest`**
  - [ ] 4.1 Mêmes étapes que 2.1-2.4 + conserver `rules()` qui est riche (cf. design §3.1). La PHPDoc `## 10-year perspective` doit rester si elle existe. _Requirements: REQ-2, REQ-3_
  - [ ] 4.2 `php -l app/Http/Requests/UpdateEvaluationRequest.php` → No syntax errors. _Requirements: REQ-2_

- [ ] **5. Audit grep post-modification**
  - [ ] 5.1 `grep -n 'klassci_data.*enseignant_id' app/Http/Requests/` → 0 hit (sauf commentaires de garde historiques pré-existants). _Requirements: REQ-5_
  - [ ] 5.2 `grep -c "use ChecksEvaluationOwnership" app/Http/Requests/` → exactement 3 hits (un par FormRequest). _Requirements: REQ-2_
  - [ ] 5.3 `grep -c "checkEvaluationOwnership" app/Http/Requests/` → au moins 3 hits (un par `authorize()` migré) + 1 dans le trait = 4 hits minimum. _Requirements: REQ-2_

- [ ] **6. Tests Unit du trait**
  - [ ] 6.1 Créer le sous-dossier `tests/Unit/Http/Requests/Concerns/`. _Requirements: REQ-4_
  - [ ] 6.2 Créer `tests/Unit/Http/Requests/Concerns/ChecksEvaluationOwnershipTest.php` avec :
    - `declare(strict_types=1);`
    - `RefreshDatabase` + skip si `!extension_loaded('pdo_pgsql')`
    - Classe FormRequest concrète de test (`TestEvaluationOwnershipRequest`) déclarée dans le même fichier, `@internal`, qui `use`s le trait
    - Helper privé `runTraitWith(?User $user, ?int $evaluationId): bool` cf. design §4.2
    - _Requirements: REQ-4_
  - [ ] 6.3 Implémenter `test_returns_false_when_user_is_not_authenticated` : `auth()` retourne `null`, asserter `false`. _Requirements: REQ-4 #1_
  - [ ] 6.4 Implémenter `test_returns_false_for_coordinateur` : user `role=coordinateur`, asserter `false`. _Requirements: REQ-4 #2_
  - [ ] 6.5 Implémenter `test_returns_false_when_evaluation_not_found` : `$evaluationId` pointe sur un id inexistant, asserter `false`. _Requirements: REQ-4 #3_
  - [ ] 6.6 Implémenter `test_returns_false_for_evaluation_in_other_institution` : éval existe dans `$otherInstitution`, user dans `$institution`, asserter `false`. _Requirements: REQ-4 #4_
  - [ ] 6.7 Implémenter `test_returns_true_for_owner_with_matching_klassci_enseignant_id` : user enseignant `klassci_enseignant_id=42`, éval `klassci_enseignant_id=42`, asserter `true`. _Requirements: REQ-4 #5_
  - [ ] 6.8 Implémenter `test_returns_true_for_admin_regardless_of_klassci_enseignant_id` : user `role=supradmin` (admin bypass), `klassci_enseignant_id=null`, éval `klassci_enseignant_id=999`, asserter `true`. _Requirements: REQ-4 #6_
  - [ ] 6.9 Implémenter `test_returns_false_for_non_admin_with_null_klassci_enseignant_id` : user `role=enseignant`, `klassci_enseignant_id=null`, éval `klassci_enseignant_id=42`, asserter `false`. _Requirements: REQ-4 #7_
  - [ ] 6.10 Implémenter `test_returns_false_for_non_owner_with_mismatched_klassci_enseignant_id` : user `klassci_enseignant_id=42`, éval `klassci_enseignant_id=999`, asserter `false`. _Requirements: REQ-4 #8_

- [ ] **7. Lint + tests sur le trait**
  - [ ] 7.1 `php -l tests/Unit/Http/Requests/Concerns/ChecksEvaluationOwnershipTest.php` → No syntax errors. _Requirements: REQ-4_
  - [ ] 7.2 `vendor/bin/phpunit tests/Unit/Http/Requests/Concerns/ChecksEvaluationOwnershipTest.php` → 8 tests chargés (skipped local sans pdo_pgsql). _Requirements: REQ-4_

- [ ] **8. Régression Feature**
  - [ ] 8.1 `vendor/bin/phpunit tests/Feature/Security` → 35 tests pré-existants chargés (aucun touché). _Requirements: REQ-5_
  - [ ] 8.2 `vendor/bin/phpunit tests/Feature/LMS` → 50 tests intacts. _Requirements: REQ-5_
  - [ ] 8.3 `vendor/bin/phpunit tests/Feature/Quiz tests/Feature/Forum tests/Feature/Notifications tests/Feature/Files` → suites intactes. _Requirements: REQ-5_

- [ ] **9. PHPStan check**
  - [ ] 9.1 `vendor/bin/phpstan analyse --no-progress --memory-limit=2G` → `[OK] No errors`. Si baseline gonfle, investiguer (refactor ne devrait jamais l'augmenter). _Requirements: critère d'acceptation §2_

- [ ] **10. Audits read-only**
  - [ ] 10.1 Lancer `spec-security` + `spec-architect` en parallèle sur le diff. Cible : 0 finding HIGH/CRITICAL. Le finding LOW DRY MEDIUM identifié sur PR #122 doit **disparaître** (objectif de cette PR). _Requirements: critères §5 + §6_
  - [ ] 10.2 Corriger les findings éventuels AVANT 10.3.
  - [ ] 10.3 Lancer `spec-reviewer` (15 questions). Verdict cible : MERGE-READY. _Requirements: critère §7_

- [ ] **11. Validation locale finale**
  - [ ] 11.1 `git diff lms..HEAD --stat`. Bilan attendu : ~6 fichiers, net ≈ +205 lignes (75 trait + 220 tests − 90 sur 3 FormRequests). _Requirements: critère §1_
  - [ ] 11.2 Bilan code applicatif : `−15 LOC net` (75 trait − 30 × 3 FormRequests = −15). Confirme l'amélioration DRY. _Requirements: critère §4_

- [ ] **12. Commit + push + PR + fermeture #125**
  - [ ] 12.1 Présenter le récap des changements à l'utilisateur AVANT `git commit` (rule `feedback_no_commit_without_approval`).
  - [ ] 12.2 Sur approbation explicite, créer 1 commit Conventional Commit type `refactor` avec mention `closes #125` dans le body. _Requirements: critère §8_
  - [ ] 12.3 `git push -u origin refactor/125-checks-evaluation-ownership-trait`.
  - [ ] 12.4 `gh pr create --base lms --title "refactor: ChecksEvaluationOwnership trait — DRY 3 evaluation FormRequests (closes #125)"` avec body-file détaillé.
  - [ ] 12.5 Attendre que l'utilisateur merge la PR côté GitHub. Pas de `gh pr merge --auto`.
  - [ ] 12.6 Post-merge : `gh issue close 125 -c "Résolu par PR #XXX..."`.

## Récap mapping `_Requirements_`

| REQ | Tasks |
|---|---|
| REQ-1 (trait créé + API minimaliste) | 1.1, 1.2, 1.3 |
| REQ-2 (3 FormRequests utilisent le trait) | 2.1-2.5, 3.1-3.2, 4.1-4.2, 5.2, 5.3 |
| REQ-3 (PHPDocs nettoyées) | 2.4, 3.1, 4.1 |
| REQ-4 (8 tests Unit du trait) | 6.1-6.10, 7.1-7.2 |
| REQ-5 (régression Feature) | 8.1-8.3 |
| REQ-6 (documentation du trait) | 1.2 |

## Estimation et risques

- **Temps estimé** : ~2h en exécution séquentielle locale. Refactor pur DRY, sans complexité d'algo.
- **Risque principal** : casser le comportement runtime via une coquille dans le copier-coller. **Mitigation** :
  - Tâche 5 (audit grep) vérifie que les 3 `use ChecksEvaluationOwnership` sont présents
  - Tâche 6 (tests Unit) couvre 8 scénarios incluant les edge cases (null user, coordinateur, cross-tenant, admin bypass)
  - Tâche 8 (régression Feature) re-run les 35 tests Security pré-existants qui exercent les 3 FormRequests via HTTP réel
- **Risque secondaire** : le trait introduit une dépendance namespace que PHPStan pourrait mal résoudre. **Mitigation** : tâche 9 (PHPStan strict).
- **Risque tertiaire** : un dev futur lit `DeleteEvaluationRequest.php` et ne comprend pas l'autorisation parce qu'elle est dans un trait. **Mitigation** : PHPDoc `@see ChecksEvaluationOwnership` explicite (tâche 2.4).
